<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Shop;
use App\Models\Task;
use App\Models\WhatsappLog;
use Illuminate\Support\Facades\Log;

/**
 * Decides HOW a WhatsApp notification reaches a staff member:
 *
 *  1. Template path (reliable, Meta-compliant): merchant created+approved
 *     the template in their own Whatify dashboard and mapped it in Settings.
 *  2. Session-text fallback: only delivers inside the 24h window.
 *
 * Every attempt is written to whatsapp_logs (review + debugging trail).
 */
class TaskNotifier
{
    public function __construct(protected Shop $shop) {}

    protected function client(): WhatifyClient
    {
        return new WhatifyClient((string) $this->shop->whatify_api_key, $this->shop->whatify_account_id);
    }

    /** Send the OTP staff-verification message. */
    public function sendOtp(Member $member, string $code): WhatsappLog
    {
        $appName  = config('app.name', 'TaskPe');
        $template = $this->shop->setting('templates.otp');

        return $this->deliver(
            member: $member,
            kind: 'otp',
            template: $template,
            templateParams: [$appName, $code],
            text: "Your {$appName} verification code is {$code}. It expires in "
                .config('whatify.otp_ttl_minutes', 10).' minutes. (Reply anything to this number to keep WhatsApp alerts instant.)',
        );
    }

    /** "New task assigned to you" ping. */
    public function notifyAssigned(Task $task, Member $member): ?WhatsappLog
    {
        if (!$this->shop->setting('notify.on_assign', true)) {
            return null;
        }

        $template = $this->shop->setting('templates.task_assigned');

        return $this->deliver(
            member: $member,
            kind: 'task_assigned',
            template: $template,
            templateParams: [
                $member->name,
                $task->title,
                $this->dueText($task),
                $this->resourceText($task),
                $this->openUrl($task),
            ],
            text: $this->assignedText($task, $member),
            task: $task,
        );
    }

    /** "Due today / overdue" reminder. */
    public function notifyReminder(Task $task, Member $member): ?WhatsappLog
    {
        $template = $this->shop->setting('templates.task_reminder') ?: $this->shop->setting('templates.task_assigned');

        return $this->deliver(
            member: $member,
            kind: 'task_reminder',
            template: $template,
            templateParams: [
                $member->name,
                $task->title,
                $this->dueText($task),
                $this->resourceText($task),
                $this->openUrl($task),
            ],
            text: "⏰ Reminder — task ".($task->isOverdue() ? 'is OVERDUE' : 'is due').": *{$task->title}*\n"
                .($task->resource_url ? "Linked {$task->resourceLabel()}: {$task->resource_url}\n" : '')
                .'Open: '.$this->openUrl($task),
            task: $task,
        );
    }

    /** Owner digest ("Bird's Eye View"). Builds one message per owner. */
    public function sendDigest(Member $owner, string $dateLabel, string $summaryBody): WhatsappLog
    {
        $template = $this->shop->setting('templates.digest');

        return $this->deliver(
            member: $owner,
            kind: 'digest',
            template: $template,
            templateParams: [$dateLabel, $summaryBody],
            text: "📋 *Team task summary — {$dateLabel}*\n\n{$summaryBody}",
        );
    }

    /** One-off test message from the Settings screen. */
    public function sendTest(Member $member): WhatsappLog
    {
        return $this->deliver(
            member: $member,
            kind: 'test',
            template: null,
            templateParams: [],
            text: '✅ '.config('app.name', 'TaskPe').' is connected to your WhatsApp. Task alerts will land here.',
        );
    }

    // ------------------------------------------------------------------

    protected function deliver(
        Member $member,
        string $kind,
        ?string $template,
        array $templateParams,
        string $text,
        ?Task $task = null,
    ): WhatsappLog {
        $log = new WhatsappLog([
            'shop_id'   => $this->shop->id,
            'member_id' => $member->id,
            'task_id'   => $task?->id,
            'kind'      => $kind,
            'phone'     => $member->phone,
            'status'    => 'queued',
        ]);

        if (!$this->shop->whatsappEnabled() && $kind !== 'test') {
            $log->fill(['status' => 'failed', 'error' => 'WhatsApp not enabled (plan/key/settings)'])->save();
            return $log;
        }

        try {
            $client  = $this->client();
            $channel = 'text';
            $result  = null;

            if ($template) {
                $channel = 'template';
                $result  = $client->sendTemplate($member->phone, $template, $templateParams);
            }

            // Fall back to plain text when no template mapped OR Meta rejected it.
            if (!$template || !($result['success'] ?? false)) {
                $channel = 'text';
                $result  = $client->sendText($member->phone, $text);
            }

            $ok = (bool) ($result['success'] ?? false);
            $log->fill([
                'channel'            => $channel,
                'payload'            => $template && $channel === 'template'
                    ? ['template' => $template, 'params' => $templateParams]
                    : ['text' => $text],
                'status'             => $ok ? ($result['status'] ?? 'sent') : 'failed',
                'whatify_message_id' => $result['message_id'] ?? null,
                'wamid'              => $result['meta_response']['wamid'] ?? null,
                'error'              => $ok ? null : ($result['error_message'] ?? json_encode($result)),
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Whatify send failed', ['shop' => $this->shop->domain, 'kind' => $kind, 'err' => $e->getMessage()]);
            $log->fill(['status' => 'failed', 'error' => $e->getMessage()])->save();
        }

        return $log;
    }

    // ---------------- formatting helpers ----------------

    public function dueText(Task $task): string
    {
        return $task->due_at
            ? $task->due_at->copy()->tz($this->shop->timezone)->format('d M Y, g:i A')
            : 'No due date';
    }

    public function resourceText(Task $task): string
    {
        return $task->resource_title
            ? "{$task->resourceLabel()} {$task->resource_title}"
            : 'None';
    }

    /** Prefer the linked Shopify object; fall back to opening the app. */
    public function openUrl(Task $task): string
    {
        return $task->resource_url ?: $this->shop->appUrl('task='.$task->id);
    }

    protected function assignedText(Task $task, Member $member): string
    {
        $lines = [
            "👋 Hi {$member->name}, a new task is assigned to you:",
            "*{$task->title}*",
            'Due: '.$this->dueText($task),
        ];
        if ($task->resource_url) {
            $lines[] = "Linked {$task->resourceLabel()}: {$task->resource_url}";
        }
        $lines[] = 'Open task: '.$this->shop->appUrl('task='.$task->id);

        return implode("\n", $lines);
    }
}
