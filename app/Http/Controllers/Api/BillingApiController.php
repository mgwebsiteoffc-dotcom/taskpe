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
    /**
     * POST /api/billing/subscribe { plan: free|starter|growth } → whatever switches the plan.
     *
     * One endpoint for every direction, because a merchant should not have to know which billing
     * model this install runs on: the Plan tab asks to switch and this answers with either a URL to
     * open or a thing that has already happened. 200 with `redirect_url` whenever a page owns the
     * decision, 422 only when there is genuinely nowhere to send them.
     */
    public function subscribe(Request $request, ShopContext $ctx)
    {
        // Asked before it validates, so a merchant on an old tab or a direct API call gets a
        // sentence rather than Shopify's rejection of appSubscriptionCreate relayed as our advice.
        //
        // In this mode the answer is not an error and not a shrug: the app's job is to hand over
        // the URL Shopify hosts for plan changes, and 1.2.3 (plan changes without support or a
        // reinstall) is exactly the test a reviewer runs on this button.
        if (BillingService::shopifyManaged()) {
            return $this->managedByShopify($ctx->shop(), $this->planFrom($request));
        }

        // `free` is accepted here because a plan change in either direction has to be possible
        // from this screen (1.2.3), and with Shopify-owned pricing the picker page is the one
        // that lists Free. In `api` mode the service still refuses it, because "free" is the
        // absence of a charge rather than a charge to create.
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys((array) config('shopify.plans', [])))],
        ]);

        $service = new BillingService($ctx->shop());

        // Downgrade-to-Free is the one direction this app can complete by itself: there is no
        // charge to create, only the current one to cancel. Sending it to a page instead would be
        // the 1.2.3 failure with extra steps.
        if ($data['plan'] === 'free' || !BillingService::isPaidPlan($data['plan'])) {
            try {
                $service->cancelSubscription();
            } catch (\Throwable $e) {
                return response()->json(['error' => 'cancel_failed', 'message' => $e->getMessage()], 502);
            }

            $fresh = $ctx->shop()->fresh() ?? $ctx->shop();

            return response()->json(['ok' => true, 'plan' => (string) $fresh->plan, 'changed' => 'cancelled']);
        }

        try {
            $url = $service->createSubscription($data['plan']);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'billing_failed', 'message' => $e->getMessage()], 502);
        }

        // Front-end breaks out of the iframe with open(url, "_top"). Approval is Shopify's to
        // require — no app may create a recurring charge the merchant has not confirmed — so this
        // returns the page rather than pretending the switch is done.
        return response()->json([
            'confirmation_url' => $url,
            'plan'             => $data['plan'],
            'changed'          => 'approval',
        ]);
    }

    /**
     * Which plan did they click? Unknown or missing is not an error here: with Shopify owning the
     * prices, the plan list answers every question, and refusing the click to demand a parameter
     * the merchant never sees would trade a working page for a strict one.
     */
    protected function planFrom(Request $request): string
    {
        $plan = mb_strtolower(trim((string) $request->input('plan', '')));

        return array_key_exists($plan, (array) config('shopify.plans', [])) ? $plan : '';
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
    private function managedByShopify(Shop $shop, string $planKey = '')
    {
        $service = new BillingService($shop);

        $link = $planKey === ''
            ? ['kind' => BillingService::plansUrl($shop) === '' ? 'none' : 'shopify-picker',
               'url'  => BillingService::plansUrl($shop)]
            : $service->planLink($planKey);

        $url = $link['url'];

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

        // `shopify-plan` means the link opens Shopify's approval page with THIS plan already
        // chosen, so the sentence says what the next click is; `shopify-picker` means the merchant
        // still has to pick there, and saying so is the difference between a smooth hand-off and a
        // page that looks like it ignored the button.
        $planName = (string) config("shopify.plans.{$planKey}.name", $planKey);

        $message = $planKey !== '' && $link['kind'] === 'shopify-plan'
            ? 'Shopify has the amount and the trial for ' . $planName . ' — approve it there and TaskPe unlocks itself.'
            : BillingService::managedMessage();

        return response()->json([
            'mode'         => 'shopify',
            'redirect_url' => $url,
            'switch_kind'  => $link['kind'],
            'plan'         => $planKey !== '' ? $planKey : null,
            'message'      => $message,
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
