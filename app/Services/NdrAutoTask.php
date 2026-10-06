<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\Task;

/**
 * Zero-click NDR rescue: courier panels (Shiprocket, Delhivery, XpressBees…)
 * push "Non-Delivery Report" events to the shop's secret intake URL
 * (Settings → COD / NDR automation). Each push becomes an urgent
 * ndr-followup checklist task — same-day action is what saves the order.
 *
 * Same hard guarantees as CodAutoTask: opt-in (OFF by default), idempotent,
 * plan-limit-aware, and only order id/number + AWB are stored (no PII).
 */
class NdrAutoTask
{
    public const ACTOR = 'TaskPe Auto (NDR)';
    public const TEMPLATE = 'ndr-followup';

    public static function maybeCreate(
        Shop $shop,
        ?string $orderName,
        ?string $awb = null,
        ?string $reason = null,
    ): ?Task {
        if (!$shop->isInstalled() || !$shop->setting('automation.ndr_auto', false)) {
            return null;
        }
        if (!$orderName && !$awb) {
            return null; // unactionable push — nothing to identify the shipment
        }

        // Normalise order reference: "1042" / "#1042" / raw numeric order id.
        $orderId = null;
        if ($orderName && preg_match('/^\d{8,}$/', $orderName)) {
            $orderId   = (int) $orderName;      // courier sent the Shopify numeric id
            $orderName = null;
        } elseif ($orderName && $orderName !== '' && $orderName[0] !== '#') {
            $orderName = '#'.ltrim($orderName, '#');
        }

        // Try to resolve the order in Shopify for a proper admin link.
        if (!$orderId && $orderName) {
            $resolved = static::resolveOrderId($shop, $orderName);
            $orderId  = $resolved['id'] ?? null;
        }

        $markerTitle = $orderName ?: ('AWB '.($awb ?: '?'));

        // Idempotent: one OPEN ndr task per order/AWB.
        $existing = $shop->tasks()
            ->where('created_by_name', self::ACTOR)
            ->whereNull('completed_at')
            ->when($orderId, fn ($q) => $q->where('resource_id', $orderId))
            ->when(!$orderId, fn ($q) => $q->where('title', 'like', '%'.$markerTitle))
            ->first();
        if ($existing) {
            return $existing;
        }

        // Respect the plan's open-task ceiling.
        $limit = (int) ($shop->planConfig()['task_limit'] ?? 0);
        if ($limit > 0 && $shop->tasks()->whereNull('completed_at')->count() >= $limit) {
            return null;
        }

        $tpl    = config('task_templates.'.self::TEMPLATE);
        $column = $shop->columns()->where('is_done_stage', false)->orderBy('position')->first()
            ?? $shop->columns()->orderBy('position')->first();
        if (!$tpl || !$column) {
            return null;
        }

        $title = ($orderId || $orderName)
            ? str_replace('{order}', $orderName ?: ('#'.$orderId), $tpl['title'])
            : 'NDR follow-up: AWB '.($awb ?: 'unknown');

        $desc = '';
        if ($reason) {
            $desc = '🚚 Courier NDR reason: '.$reason."\n";
        }
        if ($awb) {
            $desc .= '📦 AWB: '.$awb."\n";
        }
        $desc .= "\n".CodAutoTask::checklist($tpl);

        return $shop->tasks()->create([
            'column_id'       => $column->id,
            'title'           => $title,
            'description'     => trim($desc),
            'priority'        => $tpl['priority'] ?? 'urgent',
            'due_at'          => now()->addHours((int) ($tpl['due_in_hours'] ?? 12)),
            'resource_type'   => $orderId ? 'order' : null,
            'resource_id'     => $orderId,
            'resource_gid'    => $orderId ? "gid://shopify/Order/{$orderId}" : null,
            'resource_title'  => $orderId ? ($orderName ?: '#'.$orderId) : null,
            'resource_url'    => $orderId ? $shop->adminBaseUrl().'/orders/'.$orderId : null,
            'position'        => ((int) $shop->tasks()->where('column_id', $column->id)->max('position')) + 1,
            'created_by_name' => self::ACTOR,
        ]);
    }

    /** Best-effort order lookup by its display name (#1042) — never fatal. */
    protected static function resolveOrderId(Shop $shop, string $orderName): array
    {
        try {
            $data = (new ShopifyClient($shop))->graphql(
                'query ($q: String!) { orders(first: 1, query: $q) { edges { node { id legacyResourceId name } } } }',
                ['q' => 'name:'.$orderName]
            );
            $node = $data['orders']['edges'][0]['node'] ?? null;

            return ['id' => $node ? (int) $node['legacyResourceId'] : null];
        } catch (\Throwable) {
            return ['id' => null];
        }
    }
}
