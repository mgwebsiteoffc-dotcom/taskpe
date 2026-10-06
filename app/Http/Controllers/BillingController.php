<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\BillingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Hosted-page return point after the merchant approves/declines the charge.
 * Runs top-level (outside the iframe), then bounces back into the app.
 */
class BillingController extends Controller
{
    /** GET /billing/callback?shop=...&charge_id=... */
    public function callback(Request $request)
    {
        $shop = Shop::where('domain', (string) $request->query('shop'))->first();
        if (!$shop) {
            return response('Unknown shop.', 404);
        }

        $status = 'declined';
        try {
            // Source of truth is the API, not the query string.
            $result = (new BillingService($shop))->syncActiveSubscription();
            $status = $result['active'] ? 'active' : 'declined';
        } catch (\Throwable $e) {
            Log::warning('Billing sync failed', ['shop' => $shop->domain, 'err' => $e->getMessage()]);
            $status = 'error';
        }

        return redirect()->away($shop->appUrl('billing='.$status));
    }
}
