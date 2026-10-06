<?php

namespace App\Http\Controllers;

use App\Models\Member;

/**
 * GET /staff/invite/{token}
 *
 * The magic staff link: minted per member from the Team tab, revocable at
 * any time. Landing here sets a long-lived httpOnly cookie and drops the
 * teammate onto their board. Token is 48 random chars, stored hashed —
 * the URL itself is the only copy.
 */
class StaffInviteController extends Controller
{
    public function accept(string $token)
    {
        $member = Member::findByPortalToken($token);

        abort_unless($member && $member->active && $member->shop?->isInstalled(), 404);

        $cookie = cookie(
            \App\Http\Middleware\StaffPortalAuth::COOKIE,
            $token,
            60 * 24 * 60,        // 60 days, minutes
            '/',
            null,
            true,                // secure
            true,                // httpOnly — JS never reads it
            false,
            'Lax'                // blocks cross-site POSTs carrying it
        );

        return redirect('/staff')->withCookie($cookie);
    }
}
