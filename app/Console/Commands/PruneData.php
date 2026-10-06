<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use App\Models\WhatsappLog;
use Illuminate\Console\Command;

/** Keeps shared-hosting databases small (data-minimisation is also a review plus). */
class PruneData extends Command
{
    protected $signature = 'taskpe:prune';

    protected $description = 'Delete old WhatsApp logs and webhook ledger rows';

    public function handle(): int
    {
        $logs = WhatsappLog::where('created_at', '<', now()->subDays(90))->delete();
        $hooks = WebhookEvent::where('created_at', '<', now()->subDays(7))->delete();

        $this->info("Pruned {$logs} WhatsApp logs, {$hooks} webhook events.");

        return self::SUCCESS;
    }
}
