<?php

namespace App\Http\Controllers;

use App\Models\BoardColumn;
use App\Models\Shop;
use App\Services\ShopifyClient;
use App\Services\TokenVault;
use App\Services\WebhookRegistrar;
use App\Support\JwtToken;
use App\Support\ShopifyHmac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * OAuth authorization-code flow for an offline access token.
 *
 * The `state` param is a self-contained, HMAC-signed token (not a session
 * cookie) because embedded apps cannot rely on third-party cookies.
 * An offline token is required so background jobs (daily WhatsApp digest)
 * can call the Admin API when the merchant is not in the app.
 *
 * The exchange asks for the EXPIRING kind (`expiring=1` in
 * ShopifyClient::exchangeCode) because the GraphQL Admin API rejects the
 * non-expiring one for public apps. What Shopify returns is a pair with two
 * lifetimes, and TokenVault owns it from here — nothing in this controller
 * compares expiry dates or renews anything, it just lands a fresh grant.
 */
class AuthController extends Controller
{
    /** GET /auth/shopify?shop=x.myshopify.com — kick off OAuth. */
    public function redirect(Request $request)
    {
        $shop = (string) $request->query('shop', '');

        if (!JwtToken::isValidShopDomain($shop)) {
            return response('Invalid shop parameter. Expected something like mystore.myshopify.com', 400);
        }

        $query = http_build_query([
            'client_id'    => config('shopify.api_key'),
            'scope'        => config('shopify.scopes'),
            'redirect_uri' => config('shopify.app_url').'/auth/shopify/callback',
            'state'        => $this->signState($shop),
        ]);

        return redirect()->away("https://{$shop}/admin/oauth/authorize?{$query}");
    }

    /** GET /auth/shopify/callback — verify, exchange token, install. */
    public function callback(Request $request)
    {
        if (!ShopifyHmac::verifyOAuthCallback($request)) {
            return response('Invalid request signature.', 401);
        }

        $shop = (string) $request->query('shop', '');
        $code = (string) $request->query('code', '');

        if (!$this->verifyState((string) $request->query('state', ''), $shop) || !$code) {
            return response('Invalid or expired state. Please reinstall the app.', 401);
        }

        try {
            $token = ShopifyClient::exchangeCode($shop, $code);
        } catch (\Throwable $e) {
            Log::error('OAuth token exchange failed', ['shop' => $shop, 'err' => $e->getMessage()]);

            return response('Could not complete installation. Please try again.', 502);
        }

        $model = Shop::firstOrNew(['domain' => $shop]);
        $model->fill([
            'handle'         => Str::before($shop, '.'),
            'scopes'         => $token['scope'] ?? config('shopify.scopes'),
            'installed_at'   => now(),
            'uninstalled_at' => null,
            'plan'           => $model->plan ?: config('shopify.default_plan'),
        ])->save();

        // The token itself goes in through the vault, because that is the one method that
        // knows an expiring response is FOUR values (access token, refresh token, and when
        // each dies) and not the single string installs used to save. Saving only the first
        // is what left public apps working for an hour and then refusing every API call.
        (new TokenVault($model))->store($token);

        // A fresh grant is the answer to a rejected one — drop the complaint so the
        // gate, the log and taskpe:doctor stop reporting a problem that is gone.
        $model->clearTokenRejection();

        $this->hydrateShopProfile($model);
        $this->seedBoard($model);

        // Mandatory (GDPR) + lifecycle webhooks.
        (new WebhookRegistrar())->registerAll($model);

        // Land the merchant inside the embedded app.
        return redirect()->away($model->appUrl());
    }

    /** Pull display name, timezone + shop currency (localized plan pricing). */
    protected function hydrateShopProfile(Shop $shop): void
    {
        try {
            $data  = (new ShopifyClient($shop))->graphql('{ shop { name ianaTimezone currencyCode } }');
            $tz    = $data['shop']['ianaTimezone'] ?? null;
            $cur   = $data['shop']['currencyCode'] ?? null;
            $shop->fill([
                'name'     => $data['shop']['name'] ?? null,
                'timezone' => is_string($tz) && $tz !== '' ? $tz : 'Asia/Kolkata',
                'currency' => is_string($cur) && strlen($cur) === 3 ? $cur : null,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('Shop profile fetch failed', ['shop' => $shop->domain, 'err' => $e->getMessage()]);
        }
    }

    protected function seedBoard(Shop $shop): void
    {
        if ($shop->columns()->exists()) {
            return;
        }

        foreach (config('shopify.default_columns') as $i => $name) {
            BoardColumn::create([
                'shop_id'       => $shop->id,
                'name'          => $name,
                'position'      => $i,
                'is_done_stage' => $i === count(config('shopify.default_columns')) - 1,
            ]);
        }
    }

    // ---------------- signed state helpers ----------------

    protected function signState(string $shop): string
    {
        $payload = JwtToken::b64urlEncode(json_encode(['shop' => $shop, 'exp' => time() + 600]) ?: '{}');
        $sig     = hash_hmac('sha256', $payload, (string) config('shopify.api_secret'));

        return $payload.'.'.$sig;
    }

    protected function verifyState(string $state, string $shop): bool
    {
        [$payload, $sig] = array_pad(explode('.', $state, 2), 2, '');
        $expected = hash_hmac('sha256', $payload, (string) config('shopify.api_secret'));

        if ($payload === '' || !hash_equals($expected, $sig)) {
            return false;
        }

        $data = json_decode(JwtToken::b64urlDecode($payload) ?: '', true);

        return is_array($data)
            && ($data['shop'] ?? null) === $shop
            && time() < (int) ($data['exp'] ?? 0);
    }
}
