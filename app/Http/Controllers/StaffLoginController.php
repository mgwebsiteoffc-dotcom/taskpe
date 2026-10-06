<?php

namespace App\Http\Controllers;

use App\Http\Middleware\StaffPortalAuth;
use App\Jobs\SendWhatsAppJob;
use App\Models\Member;
use Illuminate\Http\Request;

/**
 * Phone + WhatsApp OTP sign-in for the staff portal — the self-serve
 * alternative to invite links (requires the shop to have WhatsApp ON and
 * the member's number OTP-verified already). Rate-limited at the routes.
 */
class StaffLoginController extends Controller
{
    /** POST /staff/login { phone } — send a code (never reveals existence). */
    public function send(Request $request)
    {
        $data   = $request->validate(['phone' => ['required', 'string', 'max:30']]);
        $member = $this->findMember($data['phone']);

        if ($member && $member->whatsapp_verified && $member->shop->whatsappEnabled()) {
            $code = (string) random_int(100000, 999999);
            $member->issueOtp($code);
            $member->save();
            SendWhatsAppJob::dispatch($member->shop_id, $member->id, 'otp', extra: ['code' => $code])->onQueue('default');
        }

        // Always ok:true — a response must not confirm whether a number is
        // a staff member on any shop.
        return response()->json(['ok' => true, 'message' => 'If this number belongs to a team member with WhatsApp enabled, a code is on its way.']);
    }

    /** POST /staff/verify { phone, code } → staff cookie. */
    public function verify(Request $request)
    {
        $data   = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'code'  => ['required', 'string', 'size:6'],
        ]);
        $member = $this->findMember($data['phone']);

        if (!$member || !$member->checkOtp($data['code'])) {
            return response()->json(['error' => 'invalid_code', 'message' => 'Wrong or expired code. Try again.'], 422);
        }

        // Verified phone → rotate into a portal token so the durable cookie
        // never depends on the OTP.
        $raw = $member->issuePortalToken();

        return response()->json(['ok' => true])
            ->withCookie(cookie(
                StaffPortalAuth::COOKIE, $raw, 60 * 24 * 60, '/', null, true, true, false, 'Lax'
            ));
    }

    /** POST /staff/logout */
    public function logout()
    {
        return response()->json(['ok' => true])
            ->withCookie(cookie()->forget(StaffPortalAuth::COOKIE, '/'));
    }

    protected function findMember(string $phone): ?Member
    {
        $digits = preg_replace('/\D/', '', $phone) ?: '';
        if (strlen($digits) === 10) {
            $digits = '91'.$digits;
        } elseif (str_starts_with($digits, '0') && strlen($digits) === 11) {
            $digits = '91'.substr($digits, 1);
        }
        if (strlen($digits) < 11) {
            return null;
        }

        return Member::query()
            ->where('phone', $digits)
            ->where('active', true)
            ->whereHas('shop', fn ($q) => $q->whereNull('uninstalled_at')->whereNotNull('access_token'))
            ->orderBy('id')
            ->first();
    }
}
