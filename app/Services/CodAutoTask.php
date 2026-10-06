<?php

namespace App\Services;

use App\Models\Shop;
use App\Models\Task;
use Illuminate\Support\Facades\Log;

/**
 * Optional zero-click COD guard: when `automation.cod_auto` is ON (Settings →
 * COD automation, OFF by default), every incoming orders/create webhook that
 * is Cash-on-Delivery gets a confirmation task auto-created from the
 * `cod-confirm` template — the single biggest RTO saver for Indian D2C.
 *
 * Hard guarantees:
 *  - respects the plan's open-task limit (never creates past Free's 50);
 *  - idempotent per order (one auto task per order, ever);
 *  - stores only order id/number — no customer PII.
 */
class CodAutoTask
{
    public const ACTOR = 'TaskPe Auto (COD)';
    public const TEMPLATE = 'cod-confirm';

    public static function maybeCreate(Shop $shop, array $order): ?Task
    {
        if (!$shop->isInstalled() || !$shop->setting('automation.cod_auto', false)) {
            return null;
        }
        if (!static::isCodOrder($order)) {
            return null;
        }

        $orderId   = (int) ($order['id'] ?? 0);
        $orderName = (string) ($order['name'] ?? ('#'.$orderId));
        if (!$orderId) {
            return null;
        }

        // Idempotent: one auto-task per order.
        $existing = $shop->tasks()
            ->where('resource_type', 'order')
            ->where('resource_id', $orderId)
            ->where('created_by_name', self::ACTOR)
            ->first();
        if ($existing) {
            return $existing;
        }

        // Respect the plan's open-task ceiling — never push Free past 50.
        if (TaskTemplates::limitReached($shop)) {
            Log::info('COD auto-task skipped: plan task limit reached', ['shop' => $shop->domain]);

            return null;
        }

        $tpl = config('task_templates.'.self::TEMPLATE);
        if (!$tpl) {
            return null;
        }

        // Same materialisation the Admin bulk action and the board picker use,
        // so a webhook-made task and a hand-made one are never formatted apart.
        return TaskTemplates::create($shop, $tpl, [
            'type'  => 'order',
            'id'    => $orderId,
            'gid'   => $order['admin_graphql_api_id'] ?? null,
            'title' => $orderName,
        ], [], self::ACTOR);
    }

    /** Kept for callers/tests (NdrAutoTask, RecurringChores) that build a
     *  checklist outside the auto-create path — one formatting rule, one file. */
    public static function checklist(array $tpl): string
    {
        return TaskTemplates::checklist($tpl);
    }

    /** COD detection across Indian gateways/panels: "Cash on Delivery", "cod", "cash_on_delivery", "COD (…)". */
    public static function isCodOrder(array $order): bool
    {
        $gateways = collect($order['payment_gateway_names'] ?? [])
            ->map(fn ($g) => strtolower((string) $g));

        // Draft-orders-style fallback: gateway field.
        if ($gateways->isEmpty() && !empty($order['gateway'])) {
            $gateways = collect([strtolower((string) $order['gateway'])]);
        }

        return $gateways->contains(fn ($g) =>
            str_contains($g, 'cash on delivery')
            || preg_match('/\bcod\b/', $g) === 1
            || str_contains($g, 'cash_on_delivery')
        );
    }

}
