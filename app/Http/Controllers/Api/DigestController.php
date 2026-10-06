<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DigestService;
use App\Support\ShopContext;

class DigestController extends Controller
{
    /** GET /api/digest/preview — render today's digest text without sending. */
    public function preview(ShopContext $ctx)
    {
        return response()->json(['summary' => (new DigestService($ctx->shop()))->buildSummary()]);
    }

    /** POST /api/digest/send — owner "send digest now" button. */
    public function send(ShopContext $ctx)
    {
        $shop = $ctx->shop();

        if (!($shop->planConfig()['digest'] ?? false)) {
            return response()->json(['error' => 'plan_limit', 'message' => 'Daily digest needs the Starter plan or above.'], 402);
        }
        if (!$shop->whatsappEnabled()) {
            return response()->json(['error' => 'whatsapp_off', 'message' => 'Connect Whatify and verify the owner\'s number first.'], 422);
        }

        $logs = (new DigestService($shop))->send();
        $ok   = collect($logs)->where('status', '!=', 'failed')->count();

        return response()->json([
            'ok'      => $ok > 0,
            'sent'    => $ok,
            'message' => $ok > 0 ? "Digest sent to {$ok} owner(s)." : 'Digest failed — check the WhatsApp log below.',
        ], $ok > 0 ? 200 : 422);
    }
}
