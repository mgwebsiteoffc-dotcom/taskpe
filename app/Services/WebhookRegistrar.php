<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Log;

/**
 * Registers all webhook subscriptions (including the 3 mandatory GDPR
 * compliance topics) over the GraphQL Admin API right after install.
 * Registering via API satisfies the mandatory-webhook requirement as long
 * as the endpoint stays reachable; we additionally document mirroring them
 * in the Partner Dashboard for belt-and-braces.
 */
class WebhookRegistrar
{
    public function registerAll(Shop $shop): void
    {
        foreach (config('shopify.webhook_topics', []) as $topic) {
            $this->ensureTopic($shop, $topic);
        }
    }

    /**
     * Idempotently register ONE topic (GraphQL enum name, e.g. ORDERS_CREATE).
     * Used at install (all topics) and lazily when a feature needing a
     * webhook is switched on later (existing installs upgrading).
     */
    public function ensureTopic(Shop $shop, string $topic): void
    {
        $client = new ShopifyClient($shop);
        $url    = rtrim((string) config('shopify.app_url'), '/').'/webhooks/shopify';

        $mutation = <<<'GQL'
        mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $webhookSubscription: WebhookSubscriptionInput!) {
          webhookSubscriptionCreate(topic: $topic, webhookSubscription: $webhookSubscription) {
            webhookSubscription { id }
            userErrors { field message }
          }
        }
        GQL;

        try {
            $client->graphql($mutation, [
                'topic'              => $topic,
                'webhookSubscription' => [
                    'callbackUrl' => $url,
                    'format'      => 'JSON',
                ],
            ], tolerateUserErrors: true); // TAKEN/errors on re-install are fine
        } catch (\Throwable $e) {
            // A webhook failure must never block the install flow.
            Log::warning('Webhook registration failed', [
                'shop'  => $shop->domain,
                'topic' => $topic,
                'err'   => $e->getMessage(),
            ]);
        }
    }
}
