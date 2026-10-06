<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppJob;
use App\Models\Member;
use App\Services\TaskNotifier;
use App\Services\WhatifyClient;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MemberController extends Controller
{
    /** POST /api/members */
    public function store(Request $request, ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $limit = (int) ($shop->planConfig()['member_limit'] ?? 0);
        if ($limit > 0 && $shop->members()->where('active', true)->count() >= $limit) {
            return response()->json(['error' => 'plan_limit', 'message' => "Your plan allows {$limit} team members. Upgrade to add more."], 402);
        }

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:20'],
            'role'  => ['required', Rule::in([Member::ROLE_OWNER, Member::ROLE_STAFF])],
        ]);

        $phone = WhatifyClient::normalizePhone($data['phone']);
        if (strlen($phone) < 11) {
            return response()->json(['error' => 'invalid_phone', 'message' => 'Enter a full mobile number (10 digits min).'], 422);
        }

        $exists = $shop->members()->where('phone', $phone)->first();
        if ($exists) {
            return response()->json(['error' => 'duplicate', 'message' => 'This number is already on your team.'], 422);
        }

        $member = $shop->members()->create([
            'name'  => $data['name'],
            'phone' => $phone,
            'role'  => $data['role'],
        ]);

        // Fire the WhatsApp verification OTP automatically if the pipe is ready.
        $otpQueued = false;
        if ($shop->whatsappEnabled()) {
            $otpQueued = $this->dispatchOtp($shop->id, $member);
        }

        return response()->json([
            'id'         => $member->id,
            'otp_queued' => $otpQueued,
            'message'    => $otpQueued
                ? 'Member added. Verification code sent on WhatsApp.'
                : 'Member added. Connect Whatify in Settings to enable WhatsApp verification.',
        ], 201);
    }

    /** PATCH /api/members/{id} */
    public function update(Request $request, ShopContext $ctx, int $id)
    {
        $member = $this->findMember($ctx, $id);

        $data = $request->validate([
            'name'   => ['sometimes', 'string', 'max:80'],
            'role'   => ['sometimes', Rule::in([Member::ROLE_OWNER, Member::ROLE_STAFF])],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['role']) && $member->isOwner() && $data['role'] === Member::ROLE_STAFF) {
            abort_if($ctx->shop()->members()->where('role', Member::ROLE_OWNER)->count() <= 1, 422, 'You need at least one owner.');
        }

        $member->update($data);

        return response()->json(['ok' => true]);
    }

    /** DELETE /api/members/{id} */
    public function destroy(ShopContext $ctx, int $id)
    {
        $member = $this->findMember($ctx, $id);

        if ($member->isOwner()) {
            abort_if($ctx->shop()->members()->where('role', Member::ROLE_OWNER)->count() <= 1, 422, 'You need at least one owner.');
        }

        $ctx->shop()->tasks()->where('assignee_id', $member->id)->update(['assignee_id' => null]);
        $member->delete();

        return response()->json(['ok' => true]);
    }

    /** POST /api/members/{id}/send-otp */
    public function sendOtp(ShopContext $ctx, int $id)
    {
        $member = $this->findMember($ctx, $id);
        $shop   = $ctx->shop();

        if (!$shop->whatsappEnabled()) {
            return response()->json(['error' => 'whatsapp_off', 'message' => 'Connect your Whatify API key in Settings first (and make sure your plan includes WhatsApp).'], 422);
        }

        $queued = $this->dispatchOtp($shop->id, $member);

        return response()->json($queued
            ? ['ok' => true, 'message' => 'Code sent on WhatsApp. Ask '.$member->name.' to share it.']
            : ['ok' => false, 'message' => 'Could not queue the message.'], $queued ? 200 : 500);
    }

    /** POST /api/members/{id}/verify { code } */
    public function verify(Request $request, ShopContext $ctx, int $id)
    {
        $member = $this->findMember($ctx, $id);

        $data = $request->validate(['code' => ['required', 'string', 'size:6']]);

        if (!$member->checkOtp($data['code'])) {
            return response()->json(['error' => 'bad_code', 'message' => 'Wrong or expired code. Send a new one.'], 422);
        }

        $member->forceFill(['whatsapp_verified' => true, 'otp_hash' => null, 'otp_expires_at' => null])->save();

        // A welcome ping also opens Meta's 24h service window for richer messages.
        if ($ctx->shop()->whatsappEnabled()) {
            SendWhatsAppJob::dispatch($ctx->id(), $member->id, 'test');
        }

        return response()->json(['ok' => true, 'message' => $member->name.' is now WhatsApp-verified.']);
    }

    /** POST /api/members/{id}/test — Settings "send test message" button. */
    public function sendTest(ShopContext $ctx, int $id)
    {
        $member = $this->findMember($ctx, $id);

        if (empty($ctx->shop()->whatify_api_key)) {
            return response()->json(['error' => 'no_key', 'message' => 'Add your Whatify API key first.'], 422);
        }

        // Synchronous so the Settings screen can show the real result.
        $log = (new TaskNotifier($ctx->shop()))->sendTest($member);

        return response()->json([
            'ok'      => $log->status !== 'failed',
            'status'  => $log->status,
            'message' => $log->status === 'failed'
                ? 'Failed: '.$log->error
                : 'Test message '.$log->status.'. Check '.$member->name.'\'s WhatsApp.',
        ], $log->status === 'failed' ? 422 : 200);
    }

    // ---------------- internals ----------------

    protected function dispatchOtp(int $shopId, Member $member): bool
    {
        $code = (string) random_int(100000, 999999);
        $member->issueOtp($code);
        $member->save();

        SendWhatsAppJob::dispatch($shopId, $member->id, 'otp', null, ['code' => $code]);

        return true;
    }

    /** POST /api/members/{id}/portal-link — mint/rotate the staff web link. */
    public function portalLink(ShopContext $ctx, int $id)
    {
        $member = $this->findMember($ctx, $id);
        $raw    = $member->issuePortalToken();   // every older link dies

        return response()->json([
            'ok'  => true,
            'url' => rtrim((string) config('shopify.app_url'), '/').'/staff/invite/'.$raw,
        ]);
    }

    /** DELETE /api/members/{id}/portal-link — revoke portal access. */
    public function revokePortalLink(ShopContext $ctx, int $id)
    {
        $this->findMember($ctx, $id)->revokePortalToken();

        return response()->json(['ok' => true, 'message' => 'Portal access revoked.']);
    }

    protected function findMember(ShopContext $ctx, int $id): Member
    {
        return $ctx->shop()->members()->where('id', $id)->firstOrFail();
    }
}
