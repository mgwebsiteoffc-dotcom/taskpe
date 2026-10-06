<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\DigestService;
use Illuminate\Console\Command;

/**
 * Runs every minute from the scheduler; sends each shop's digest exactly at
 * the time the owner picked (evaluated in the SHOP's timezone).
 */
class SendDigests extends Command
{
    protected $signature = 'taskpe:send-digests';

    protected $description = 'Send due daily WhatsApp digests to shop owners';

    public function handle(): int
    {
        Shop::query()
            ->whereNull('uninstalled_at')
            ->whereNotNull('whatify_api_key')
            ->where('plan', '!=', config('shopify.default_plan'))
            ->chunkById(50, function ($shops) {
                foreach ($shops as $shop) {
                    $service = new DigestService($shop);
                    if (!$service->isDueNow()) {
                        continue;
                    }
                    try {
                        $logs = $service->send();
                        $this->info("{$shop->domain}: ".count($logs).' digest(s)');
                    } catch (\Throwable $e) {
                        $this->error("{$shop->domain}: {$e->getMessage()}");
                    }
                }
            });

        return self::SUCCESS;
    }
}
