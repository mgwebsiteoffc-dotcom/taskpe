<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\BillingService;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillingApiController extends Controller
{
    /** POST /api/billing/subscribe { plan: starter|growth } → Shopify-hosted confirmation URL. */
    public function subscribe(Request $request, ShopContext $ctx)
    {
        // Asked before it validates, so a merchant on an old tab or a direct API call gets a
        // sentence rather than Shopify's rejection of appSubscriptionCreate relayed as our advice.
        //
        // In this mode the answer is not an error and not a shrug: the app's job is to hand over
        // the URL Shopify hosts for plan changes, and 1.2.3 (plan changes without support or a
        // reinstall) is exactly the test a reviewer runs on this button.
        if (BillingService::shopifyManaged()) {
            return $this->managedByShopify($ctx->shop());
        }

        // `free` is accepted here because a plan change in either direction has to be possible
        // from this screen (1.2.3), and with Shopify-owned pricing the picker page is the one
        // that lists Free. In `api` mode the service still refuses it, because "free" is the
        // absence of a charge rather than a charge to create.
        $data = $request->validate([
            'plan' => ['required', Rule::in(['starter', 'growth', 'free'])],
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
        if (BillingService::shopifyManaged()) {
            return $this->managedByShopify($ctx->shop());
        }

        try {
            (new BillingService($ctx->shop()))->cancelSubscription();
        } catch (\Throwable $e) {
            return response()->json(['error' => 'cancel_failed', 'message' => $e->getMessage()], 502);
        }

        return response()->json(['ok' => true, 'plan' => $ctx->shop()->plan]);
    }

    /**
     * Shopify owns the money, so the app's response is the address of Shopify's page rather
     * than a charge. 200 + `redirect_url` on purpose: the SPA has ONE behaviour for both billing
     * modes — open whatever URL came back at top level — so a plan button can never degrade into
     * "nothing happened", which is the failure this endpoint is here to remove.
     *
     * 422 only when the link cannot be built at all, and the message then names the missing
     * .env line: that is our misconfiguration, and a merchant should not be told to go looking
     * through Settings → Apps for a page we could have linked.
     */
    private function managedByShopify(Shop $shop)
    {
        $url = BillingService::plansUrl($shop);

        if ($url === '') {
            return response()->json([
                'error'   => 'plans_url_not_configured',
                'message' => 'The link to Shopify\'s plan page is not set up on this install, so this page '
                    .'cannot open it for you. Until it is, change the plan at Shopify admin → Settings → Apps and '
                    .'sales channels → '.config('app.name').' → plan / billing. (Developer note: set '
                    .'SHOPIFY_APP_HANDLE — Partner Dashboard → your app → Settings → General → App handle — or '
                    .'SHOPIFY_APP_PLANS_URL, then php artisan config:clear.)',
            ], 422);
        }

        // Remember where to send the merchant back to, because Shopify's own redirect comes
        // without any session context — see BillingController::callback().
        \Illuminate\Support\Facades\Cookie::queue(
            'taskpe_billing_return',
            $shop->domain,
            30,
            '/billing/callback',
            null,
            true,
            true,
            'Lax'
        );

        return response()->json([
            'mode'         => 'shopify',
            'redirect_url' => $url,
            'message'      => BillingService::managedMessage(),
        ]);
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
