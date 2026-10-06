<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessShopifyWebhook;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;

/**
 * Single endpoint for ALL webhook topics:
 *   app/uninstalled + customers/data_request, customers/redact, shop/redact
 *
 * Flow: HMAC middleware → idempotency ledger → queue → 200 fast.
 * Shopify expects a quick 2xx; heavy work happens in the job.
 */
class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        $topic     = (string) $request->header('X-Shopify-Topic', '');
        $domain    = (string) $request->header('X-Shopify-Shop-Domain', '');

        // Idempotency: skip duplicate deliveries.
        $event = WebhookEvent::firstOrCreate(
            ['webhook_id' => $webhookId !== '' ? $webhookId : sha1($topic.$domain.$request->getContent())],
            ['shop_domain' => $domain, 'topic' => $topic]
        );

        if (!$event->wasRecentlyCreated) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        ProcessShopifyWebhook::dispatch($event->id, $request->all());

        return response()->json(['ok' => true]);
    }
}
