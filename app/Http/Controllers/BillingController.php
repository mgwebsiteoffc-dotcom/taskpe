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
    /**
     * GET /billing/callback — the landing point after Shopify's plan page (approve, decline,
     * or change) and after an app-created charge.
     *
     * Shopify App Pricing appends `plan_handle` and the shop domain to the configured redirect
     * URL (before 28 April 2026 it also appended `charge_id`), and none of it is signed, so the
     * query string is treated as a *hint about where to look* and never as proof of a plan:
     * `activeSubscriptions` on the store's own installation is what decides, and the app's
     * `plan` column follows that answer rather than the URL.
     *
     * `taskpe_billing_return` is the fallback identity: the merchant left the app from an
     * endpoint that knew which store it was, and Shopify brings them back without a session.
     * Without it, a returning merchant would land on "Unknown shop" having done nothing wrong.
     */
    public function callback(Request $request)
    {
        $domain = $this->domainFrom($request);
        $shop   = $domain === '' ? null : Shop::where('domain', $domain)->first();

        if (!$shop) {
            // Deliberately not a 404 with a code in it: the merchant has just approved (or
            // declined) something at Shopify and is one redirect from our app. Say what
            // happened, and put them where the Plan tab will read the truth.
            return response()->view('billing-return', ['resolved' => false, 'domain' => $domain], 200);
        }

        $planHandle = trim((string) $request->query('plan_handle', ''));
        $status     = 'declined';

        try {
            $service = new BillingService($shop);
            $result  = $service->syncActiveSubscription($planHandle !== '' ? $planHandle : null);
            $status  = $result['active'] ? 'active' : 'declined';

            // Shopify named the plan it just sold. Keep that pairing: the next switch can then
            // open that plan's own approval page instead of the list, using a handle Shopify
            // supplied rather than one this app guessed.
            if ($status === 'active') {
                $service->rememberPlanHandle($result['plan'] ?? null, $planHandle);
            }

            // A plan_handle from Shopify with nothing active on the installation yet is a
            // propagation gap, not a refusal. Saying "declined" here is how a merchant ends up
            // paying for a plan their board still limits, so the SPA gets `pending` and offers
            // the re-check instead of a conclusion.
            if ($status === 'declined' && $planHandle !== '') {
                $status = 'pending';
            }
        } catch (\Throwable $e) {
            Log::warning('Billing sync failed', [
                'shop'        => $shop->domain,
                'plan_handle' => $planHandle ?: null,
                'err'         => $e->getMessage(),
            ]);
            $status = 'error';
        }

        Log::info('Merchant returned from the Shopify plan page', [
            'shop'        => $shop->domain,
            'plan_handle' => $planHandle ?: null,
            'charge_id'   => $request->query('charge_id') ?: $request->query('app_charge_id'),
            'resolved'    => $status,
        ]);

        $query = http_build_query(array_filter([
            'billing'     => $status,
            'plan_handle' => $planHandle ?: null,
        ], fn ($v) => $v !== null && $v !== ''));

        $response = redirect()->away($shop->appUrl($query));

        return $response->withCookie(\Illuminate\Support\Facades\Cookie::forget('taskpe_billing_return'));
    }

    /**
     * Which store is this? Shopify's parameter first, then the marker the app set when it sent
     * the merchant out. `myshopify_domain` is accepted because the appended parameter's name has
     * changed before, and an install must not hinge on that.
     */
    protected function domainFrom(Request $request): string
    {
        foreach (['shop', 'myshopify_domain'] as $key) {
            $value = mb_strtolower(trim((string) $request->query($key)));

            if ($value !== '') {
                return preg_replace('#^https?://#', '', $value);
            }
        }

        $cookie = $request->cookie('taskpe_billing_return');

        return is_string($cookie) ? mb_strtolower(trim($cookie)) : '';
    }
}
