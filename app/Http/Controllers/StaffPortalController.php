<?php

namespace App\Http\Controllers;

use App\Models\Member;
use Illuminate\Http\Request;

/**
 * GET /staff — the staff web portal (no Shopify admin needed).
 * Serves the board shell when a valid staff cookie exists, otherwise the
 * phone + WhatsApp-OTP sign-in screen.
 */
class StaffPortalController extends Controller
{
    public function index(Request $request)
    {
        $token  = (string) $request->cookie(\App\Http\Middleware\StaffPortalAuth::COOKIE, '');
        $member = $token !== '' ? Member::findByPortalToken($token) : null;

        if (!$member || !$member->active || !$member->shop?->isInstalled()) {
            $member = null;   // render sign-in instead
        }

        return view('staff', [
            'member' => $member,
            // Request-derived base URL: correct under any public host/preview proxy.
            'appUrl' => rtrim(request()->getSchemeAndHttpHost(), '/'),
        ]);
    }
}
