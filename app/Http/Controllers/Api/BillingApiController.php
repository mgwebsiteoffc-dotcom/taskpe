<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\BillingService;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingApiController extends Controller
{
    /** POST /api/billing/subscribe { plan: starter|growth } → Shopify-hosted confirmation URL. */
    public function subscribe(Request $request, ShopContext $ctx)
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['starter', 'growth'])],
        ]);

        try {
            $url = (new BillingService($ctx->shop()))->createSubscription($data['plan']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'billing_failed', 'message' => $e->getMessage()], 502);
        }

        // Front-end breaks out of the iframe with open(url, "_top").
        return response()->json(['confirmation_url' => $url]);
    }

    /** POST /api/billing/cancel — back to Free. */
    public function cancel(ShopContext $ctx)
    {
        try {
            (new BillingService($ctx->shop()))->cancelSubscription();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'cancel_failed', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true, 'plan' => $ctx->shop()->plan]);
    }

    /** POST /api/billing/sync — refresh status (also used after approve). */
    public function sync(ShopContext $ctx)
    {
        try {
            $result = (new BillingService($ctx->shop()))->syncActiveSubscription();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'sync_failed', 'message' => $e->getMessage()], 502);
        }

        return response()->json($result);
    }
}
