<?php

namespace App\Http\Controllers;

use App\Support\JwtToken;
use Illuminate\Http\Request;

class AppController extends Controller
{
    /**
     * GET / — the embedded app shell.
     *
     * Opened directly (e.g. from a support link) with ?shop= it first forces
     * the OAuth flow at top level, because inside an iframe we cannot render
     * Shopify's grant screen.
     */
    public function index(Request $request)
    {
        $shop = (string) $request->query('shop', '');
        $host = (string) $request->query('host', '');

        // If Shopify didn't hand us an embedded context, start OAuth.
        // (When loaded inside admin, both shop and host are always present.)
        if ($shop !== '' && $host === '' && JwtToken::isValidShopDomain($shop)) {
            return redirect('/auth/shopify?shop='.urlencode($shop));
        }

        return view('app', [
            'apiKey' => config('shopify.api_key'),
            'appUrl' => config('shopify.app_url'),
        ]);
    }

    /** GET /privacy — public privacy policy (required for app listing). */
    public function privacy()
    {
        return view('privacy', ['appName' => config('app.name')]);
    }
}
