<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Shop;
use App\Models\Task;

/**
 * The "Bird's Eye View": a morning WhatsApp to every verified owner/admin
 * summarising what the whole remote team did yesterday and what's on today.
 */
class DigestService
{
    public function __construct(protected Shop $shop) {}

    /** Build the summary body (shared by the cron command and "Send now"). */
    public function buildSummary(): string
    {
        $tz        = $this->shop->timezone;
        $yesterday = now($tz)->subDay()->startOfDay();
        $todayOpen = now($tz)->endOfDay();

        $members = $this->shop->members()->where('active', true)->get();
        if ($members->isEmpty()) {
            return 'No team members added yet.';
        }

        $lines = [];
        foreach ($members as $member) {
            $doneYesterday = Task::where('shop_id', $this->shop->id)
                ->where('assignee_id', $member->id)
                ->whereBetween('completed_at', [$yesterday, $yesterday->copy()->endOfDay()])
                ->count();

            $open  = Task::where('shop_id', $this->shop->id)
                ->where('assignee_id', $member->id)
                ->whereNull('completed_at')
                ->count();

            $overdue = Task::where('shop_id', $this->shop->id)
                ->where('assignee_id', $member->id)
                ->whereNull('completed_at')
                ->whereNotNull('due_at')
                ->where('due_at', '<', now())
                ->count();

            // Plain text, no emoji: this string is also rendered inside the app
            // (Settings → digest preview), where pictographs look out of place
            // and render per-OS. Status is carried by the numbers.
            $lines[] = sprintf(
                '%s — done yesterday: %d · open: %d · overdue: %d%s',
                $member->name,
                $doneYesterday,
                $open,
                $overdue,
                $overdue > 0 ? '  (needs attention)' : ''
            );
        }

        $unassigned = Task::where('shop_id', $this->shop->id)
            ->whereNull('assignee_id')
            ->whereNull('completed_at')
            ->count();
        if ($unassigned > 0) {
            $lines[] = "Unassigned: {$unassigned} task(s)";
        }

        return implode("\n", $lines);
    }

    /** Send the digest to all verified owners. Returns per-member logs. */
    public function send(bool $onlyIfDue = false): array
    {
        if (!$this->shop->setting('digest.enabled', true)) {
            return [];
        }

        $results = [];
        $owners  = $this->shop->members()
            ->where('role', Member::ROLE_OWNER)
            ->where('active', true)
            ->where('whatsapp_verified', true)
            ->get();

        if ($owners->isEmpty()) {
            return [];
        }

        $notifier = new TaskNotifier($this->shop);
        $label    = now($this->shop->timezone)->format('d M Y');
        $summary  = $this->buildSummary();

        foreach ($owners as $owner) {
            $results[] = $notifier->sendDigest($owner, $label, $summary);
        }

        return $results;
    }

    /** Is this shop due for its digest in the current minute? */
    public function isDueNow(): bool
    {
        $time = $this->shop->setting('digest.time', '09:00');

        return now($this->shop->timezone)->format('H:i') === $time;
    }
}
