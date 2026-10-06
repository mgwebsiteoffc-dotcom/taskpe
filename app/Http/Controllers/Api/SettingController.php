<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatifyClient;
use App\Support\ShopContext;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /** GET /api/settings — current wiring + Whatify account info + recent logs. */
    public function show(ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $whatify = ['connected' => false];
        if (!empty($shop->whatify_api_key)) {
            try {
                $client  = new WhatifyClient((string) $shop->whatify_api_key, $shop->whatify_account_id);
                $ping    = $client->ping();
                $accounts = $client->accounts();
                $wallet  = $client->wallet();

                $whatify = [
                    'connected' => ($ping['status'] ?? '') === 'ok',
                    'accounts'  => $accounts['whatsapp_accounts'] ?? [],
                    'default_account_id' => $accounts['default_whatsapp_account_id'] ?? null,
                    'wallet'    => $wallet,
                    'templates' => collect($client->templates())->map(fn ($t) => [
                        'name'     => $t['name'],
                        'category' => $t['category'] ?? null,
                        'status'   => $t['status'] ?? null,
                        'body'     => $t['body'] ?? null,
                    ])->values(),
                ];
            } catch (\Throwable $e) {
                $whatify = ['connected' => false, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'whatify'  => $whatify,
            'settings' => [
                'has_api_key'          => !empty($shop->whatify_api_key),
                'api_key_hint'         => $shop->whatify_api_key ? 'wfy_••••••'.substr((string) $shop->whatify_api_key, -4) : null,
                'whatsapp_account_id'  => $shop->whatify_account_id,
                'templates'            => $shop->setting('templates', []),
                'notify'               => [
                    'whatsapp_on' => $shop->setting('notify.whatsapp_on', false), // master switch — OFF by default
                    'on_assign'   => $shop->setting('notify.on_assign', true),
                ],
                'digest'               => [
                    'enabled' => $shop->setting('digest.enabled', true),
                    'time'    => $shop->setting('digest.time', '09:00'),
                ],
                'automation'           => [
                    'cod_auto' => $shop->setting('automation.cod_auto', false), // COD auto-task — OFF by default
                    'ndr_auto' => $shop->setting('automation.ndr_auto', false), // NDR watcher — OFF by default
                    'weekly_remittance' => $shop->setting('automation.weekly_remittance', false), // weekly chore — OFF by default
                    'ndr_intake_url' => $shop->setting('automation.ndr_token')
                        ? rtrim((string) config('shopify.app_url'), '/').'/webhooks/ndr/'.$shop->setting('automation.ndr_token')
                        : null,
                ],
            ],
            'plan'     => ['key' => $shop->plan, 'cfg' => $shop->planConfig()],
            'logs'     => $shop->whatsappLogs()->orderByDesc('id')->limit(30)->get()->map(fn ($l) => [
                'id'         => $l->id,
                'kind'       => $l->kind,
                'phone'      => $l->phone,
                'channel'    => $l->channel,
                'status'     => $l->status,
                'error'      => $l->error,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values(),
            'suggested_templates' => $this->suggestedTemplates(),
        ]);
    }

    /** PUT /api/settings */
    public function update(Request $request, ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $data = $request->validate([
            'whatify_api_key'         => ['nullable', 'string', 'max:190'],
            'whatsapp_account_id'     => ['nullable', 'integer'],
            'templates'               => ['nullable', 'array'],
            'templates.*'             => ['nullable', 'string', 'max:190'],
            'notify.whatsapp_on'      => ['nullable', 'boolean'],
            'notify.on_assign'        => ['nullable', 'boolean'],
            'digest.enabled'          => ['nullable', 'boolean'],
            'digest.time'             => ['nullable', 'date_format:H:i'],
            'automation.cod_auto'     => ['nullable', 'boolean'],
            'automation.ndr_auto'     => ['nullable', 'boolean'],
            'automation.weekly_remittance' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['whatify_api_key'])) {
            $key = trim($data['whatify_api_key']);

            // Validate the key live before persisting it.
            $client  = new WhatifyClient($key);
            $ping    = rescue(fn () => $client->ping(), null, report: false);
            if (($ping['status'] ?? null) !== 'ok') {
                return response()->json(['error' => 'bad_key', 'message' => 'Whatify rejected this API key. Check whatify.in dashboard → API keys.'], 422);
            }
            $shop->whatify_api_key = $key;
        }

        if (array_key_exists('whatsapp_account_id', $data)) {
            $shop->whatify_account_id = $data['whatsapp_account_id'] ?: null;
        }

        foreach (['templates', 'notify', 'digest', 'automation'] as $section) {
            foreach ((array) ($data[$section] ?? []) as $k => $v) {
                $shop->setSetting("{$section}.{$k}", $v);
            }
        }

        // Turning the COD guard ON needs orders/create — registered lazily so
        // installs from before this feature get it without re-installing.
        if (($data['automation']['cod_auto'] ?? null) === true) {
            (new \App\Services\WebhookRegistrar())->ensureTopic($shop, 'ORDERS_CREATE');
        }

        // Turning the NDR watcher ON mints the courier intake token once.
        if (($data['automation']['ndr_auto'] ?? null) === true
            && !$shop->setting('automation.ndr_token')) {
            $shop->setSetting('automation.ndr_token', \Illuminate\Support\Str::random(40));
        }

        $shop->save();

        return response()->json(['ok' => true, 'message' => 'Settings saved.']);
    }

    /** POST /api/onboarding/complete — mark the first-run tour as seen. */
    public function completeOnboarding(ShopContext $ctx)
    {
        $shop = $ctx->shop();
        $shop->setSetting('onboarded_at', now()->toIso8601String());
        $shop->save();

        return response()->json(['ok' => true]);
    }

    /** Exact copy-paste template bodies for the merchant's Whatify dashboard. */
    protected function suggestedTemplates(): array
    {
        $app = config('app.name', 'TaskPe');

        return [
            [
                'key'      => 'otp',
                'category' => 'utility',
                'name'     => 'taskpe_otp',
                'body'     => "Your {$app} verification code is {{1}}. It expires in 10 minutes.",
                'vars'     => 2,
                'note'     => 'Sent when a team member is added / re-verified.',
            ],
            [
                'key'      => 'task_assigned',
                'category' => 'utility',
                'name'     => 'taskpe_task_assigned',
                'body'     => "Hi {{1}}, new task assigned: {{2}}\nDue: {{3}}\nLinked: {{4}}\nOpen: {{5}}",
                'vars'     => 5,
                'note'     => 'Sent the moment a task is assigned to a verified member.',
            ],
            [
                'key'      => 'task_reminder',
                'category' => 'utility',
                'name'     => 'taskpe_task_reminder',
                'body'     => "Reminder, {{1}} — task needs attention: {{2}}\nDue: {{3}}\nLinked: {{4}}\nOpen: {{5}}",
                'vars'     => 5,
                'note'     => 'Sent for due-today reminders and manual nudges.',
            ],
            [
                'key'      => 'digest',
                'category' => 'utility',
                'name'     => 'taskpe_daily_digest',
                'body'     => "Team task summary for {{1}}:\n{{2}}",
                'vars'     => 2,
                'note'     => 'The owner\'s morning Bird\'s-Eye-View digest.',
            ],
        ];
    }
}
