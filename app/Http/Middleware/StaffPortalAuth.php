<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Support\ShopContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Authenticates the staff web portal (/staff/api/*) via the signed
 * per-member invite-link token stored in the `taskpe_staff` cookie.
 * Populates the SAME ShopContext as the admin JWT middleware, so all
 * downstream tenant scoping (task rules, resource search…) is identical.
 */
class StaffPortalAuth
{
    public const COOKIE = 'taskpe_staff';

    public function handle(Request $request, Closure $next)
    {
        $token  = (string) $request->cookie(self::COOKIE, '');
        $member = $token !== '' ? Member::findByPortalToken($token) : null;

        if (!$member || !$member->active || !$member->shop?->isInstalled()) {
            return response()->json([
                'error'   => 'staff_auth',
                'message' => 'Staff session expired — ask your manager for a fresh portal link.',
            ], 401);
        }

        app(ShopContext::class)->set($member->shop, [], $member);

        return $next($request);
    }
}
