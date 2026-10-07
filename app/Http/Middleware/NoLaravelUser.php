<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Answers "who is logged in?" with "nobody", before anything has to ask.
 *
 * This app deliberately has no Laravel guards (config/auth.php: identity is a Shopify
 * session token or a signed staff cookie, never a guard + users table). That is fine
 * right up to the moment a framework middleware calls `$request->user()` — and `throttle:`
 * calls it as the FIRST thing it does, to decide whose quota to spend. With no guard
 * configured the question does not come back "nobody": AuthManager tries to resolve a
 * guard named after `auth.defaults.guard` (null) and throws
 * "Auth guard [] is not defined." — a 500 on every throttled POST, which is how staff
 * WhatsApp sign-in and the courier NDR intake died while looking like an auth problem.
 *
 * The resolver here returns null, which is the truth for this app. Rate limiting keeps
 * working unchanged: with no user it keys by route domain + IP, exactly as it did on
 * every unauthenticated request before. And a call that genuinely wants a guard
 * (`auth()->guard('web')…`) still fails loudly instead of quietly half-logging-in a
 * user that cannot exist — that loudness is config/auth.php's design, and this
 * middleware preserves it.
 */
class NoLaravelUser
{
    public function handle(Request $request, Closure $next)
    {
        // Untyped on purpose: the framework passes an optional guard name, and this is the
        // one piece of wiring that must never throw. We ignore it — there is no guard to name.
        $request->setUserResolver(fn ($guard = null) => null);

        return $next($request);
    }
}
