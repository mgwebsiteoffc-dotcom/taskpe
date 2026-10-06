<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\WebhookRegistrar;
use Illuminate\Console\Command;

/** Repair tool: php artisan taskpe:register-webhooks mystore.myshopify.com */
class RegisterWebhooks extends Command
{
    protected $signature = 'taskpe:register-webhooks {shop : e.g. mystore.myshopify.com}';

    protected $description = '(Re)register all webhook subscriptions for a shop';

    public function handle(): int
    {
        $shop = Shop::where('domain', $this->argument('shop'))->firstOrFail();

        (new WebhookRegistrar())->registerAll($shop);

        $this->info('Webhooks (re)registered for '.$shop->domain);

        return self::SUCCESS;
    }
}
