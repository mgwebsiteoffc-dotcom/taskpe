<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\NdrAutoTask;
use Illuminate\Http\Request;

/**
 * POST /webhooks/ndr/{token}
 *
 * Courier-facing intake for NDR events (Shiprocket / Delhivery / XpressBees
 * webhook settings panels let merchants register a push URL). The token IS
 * the credential: a 40-char random per-shop secret generated when the
 * merchant enables the feature (Settings → COD / NDR automation).
 * Rate-limited at the route layer; always answers fast with 2xx.
 */
class NdrIntakeController extends Controller
{
    public function handle(Request $request, string $token)
    {
        if (strlen($token) !== 40) {
            abort(404);
        }

        $shop = Shop::where('settings->automation->ndr_token', $token)->first();
        if (!$shop || !$shop->setting('automation.ndr_auto', false)) {
            abort(404);  // don't confirm whether such a token exists
        }

        // Accept the common payload shapes used by Indian courier panels.
        $p = $request->all();

        $orderRef = static::firstString($p, [
            'order_name', 'order_number', 'order_id', 'order',
            'shipment.order_id', 'shipment.order_number',
        ]);
        $awb = static::firstString($p, [
            'awb', 'awb_code', 'tracking_number', 'waybill',
            'shipment.awb', 'shipment.awb_code',
        ]);
        $reason = static::firstString($p, [
            'ndr_reason', 'reason', 'status_remarks', 'remarks', 'status',
            'shipment.ndr_reason', 'shipment.status_remarks',
        ]);

        $task = NdrAutoTask::maybeCreate($shop, $orderRef, $awb, $reason);

        return response()->json(['ok' => true, 'task_id' => $task?->id]);
    }

    protected static function firstString(array $p, array $keys): ?string
    {
        foreach ($keys as $key) {
            $v = data_get($p, $key);
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
            if (is_int($v)) {
                return (string) $v;
            }
        }

        return null;
    }
}
