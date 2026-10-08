<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Handles the lifecycle/GDPR topics off the HTTP path.
 *
 * GDPR notes (public app requirement):
 *  - customers/data_request: this app stores NO customer PII (only order
 *    numbers / product titles / customer display names as link labels), so
 *    there is nothing to export — we log that we hold nothing.
 *  - customers/redact: same — nothing stored, nothing to redact.
 *  - shop/redact: 48h after uninstall Shopify tells us to erase the shop's
 *    data; we hard-delete the tenant (FK cascades wipe everything).
 */
class ProcessShopifyWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        public int $eventId,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (!$event || $event->processed_at) {
            return;
        }

        $shop = Shop::where('domain', $event->shop_domain)->first();

        switch ($event->topic) {
            case 'app/uninstalled':
                if ($shop) {
                    $shop->forceFill([
                        'uninstalled_at'    => now(),
                        'access_token'      => null,  // token is revoked by Shopify anyway
                        'whatify_api_key'   => null,  // don't keep merchant secrets of ex-customers
                        'plan'              => config('shopify.default_plan'),
                        'charge_id'         => null,
                    ])->save();
                }
                break;

            case 'orders/create':
                // Optional COD guard (Settings → COD automation, OFF by
                // default): auto-creates the cod-confirm template task.
                if ($shop) {
                    \App\Services\CodAutoTask::maybeCreate($shop, $this->payload);
                }
                break;

            case 'app_subscriptions/update':
                // Shopify owns the plan, so it can be upgraded, downgraded or cancelled while
                // nobody has this app open — and the app would then be enforcing yesterday's
                // limits against today's invoice. Re-read the subscription (which also refreshes
                // the amount/currency the Plan tab quotes). A failure here must not kill the
                // event: the next board load's sync, or the Plan tab, recovers it.
                if ($shop) {
                    try {
                        (new \App\Services\BillingService($shop))->syncActiveSubscription();
                    } catch (\Throwable $e) {
                        Log::warning('app_subscriptions/update: could not re-read the subscription', [
                            'shop' => $event->shop_domain, 'err' => $e->getMessage(),
                        ]);
                    }
                }
                break;

            case 'customers/data_request':
            case 'customers/redact':
                // By design we hold no customer PII for this shop — nothing
                // to export or delete. Logged for audit.
                Log::info('GDPR '.$event->topic.' — no customer PII held', ['shop' => $event->shop_domain]);
                break;

            case 'shop/redact':
                if ($shop) {
                    $shop->forceFill(['redacted_at' => now()])->save();
                    $shop->delete(); // cascades: members, columns, tasks, activities, logs
                }
                break;
        }

        $event->forceFill(['processed_at' => now()])->save();
    }
}
