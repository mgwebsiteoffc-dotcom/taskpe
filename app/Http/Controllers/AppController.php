<?php

namespace App\Http\Controllers;

use App\Support\JwtToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class AppController extends Controller
{
    /** Sections reachable as their own path (see routes/web.php). */
    public const SECTIONS = ['board', 'team', 'settings', 'plan'];

    /**
     * GET / — the embedded app shell.
     *
     * Opened directly (e.g. from a support link) with ?shop= it first forces
     * the OAuth flow at top level, because inside an iframe we cannot render
     * Shopify's grant screen.
     *
     * Without an embedded context (?shop= + ?host= from the admin) the shell
     * still renders, but it now says what is missing — either a login step on
     * the merchant's side or a server-side config gap — instead of the SPA
     * dying on one generic "something went wrong" sentence.
     */
    public function index(Request $request)
    {
        return $this->shell($request, 'board');
    }

    /** GET /{section} — the same shell, opened on one section of the app. */
    public function section(Request $request, string $section)
    {
        return $this->shell($request, in_array($section, self::SECTIONS, true) ? $section : 'board');
    }

    protected function shell(Request $request, string $section)
    {
        $shop = (string) $request->query('shop', '');
        $host = (string) $request->query('host', '');

        // If Shopify didn't hand us an embedded context, start OAuth.
        // (When loaded inside admin, both shop and host are always present.)
        if ($shop !== '' && $host === '' && JwtToken::isValidShopDomain($shop)) {
            return redirect('/auth/shopify?shop='.urlencode($shop));
        }

        $missing = $this->missingConfig();

        return view('app', [
            // ?view= still wins: extension/deep links (and the staff board) can
            // point at a section without needing the pretty path.
            'section'      => in_array((string) $request->query('view', ''), self::SECTIONS, true)
                ? (string) $request->query('view')
                : $section,
            'apiKey'       => config('shopify.api_key'),
            // Never trust the Host header for link building (the app sits
            // behind proxies with trustProxies('*')); APP_URL is authoritative
            // and only left empty when the SPA should stay same-origin.
            'appUrl'       => rtrim((string) config('shopify.app_url'), '/'),
            'shop'         => JwtToken::isValidShopDomain($shop) ? $shop : '',
            'host'         => $host,
            'embedded'     => $shop !== '' && $host !== '',
            'missingConfig' => $missing,
        ]);
    }

    /**
     * Cheap pre-flight over the settings that turn into a blank board.
     * Names only — never values — so this is safe to render for a merchant.
     *
     * @return list<string>
     */
    protected function missingConfig(): array
    {
        $missing = [];

        if (empty(config('app.key'))) {
            $missing[] = 'APP_KEY is empty — run php artisan key:generate (shop tokens are encrypted with it).';
        }

        if (empty(config('shopify.api_key'))) {
            $missing[] = 'SHOPIFY_API_KEY is empty — copy it from Partner Dashboard → Client credentials.';
        }

        if (empty(config('shopify.api_secret'))) {
            $missing[] = 'SHOPIFY_API_SECRET is empty — without it no App Bridge session token can be verified.';
        }

        if (config('shopify.app_url') === '') {
            $missing[] = 'APP_URL is empty — set it to this app\'s public https:// URL (no trailing slash); OAuth redirect_uri and asset links use it.';
        }

        // Only poke the database when the obvious config is in place, so a
        // cold shared-hosting box doesn't spend 30s on a connection timeout
        // just to show a page that could have said "set APP_KEY".
        if ($missing === []) {
            try {
                if (!Schema::hasTable('shops')) {
                    $missing[] = 'The shops table is missing — run php artisan migrate --force.';
                }
            } catch (\Throwable $e) {
                $missing[] = 'Database not reachable ('.class_basename($e).') — check DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD, then run php artisan migrate --force.';
            }
        }

        return $missing;
    }

    /** GET /privacy — public privacy policy (required for the app listing). */
    public function privacy()
    {
        return view('privacy', ['appName' => config('app.name')]);
    }
}
