<?php

namespace App\Console\Commands;

use App\Services\RecurringChores;
use Illuminate\Console\Command;

class RecurringChoresCommand extends Command
{
    protected $signature = 'taskpe:recurring-chores';
    protected $description = 'Create due recurring chores (e.g. weekly COD remittance reconciliation) for shops that enabled them';

    public function handle(RecurringChores $chores): int
    {
        $created = $chores->run();

        if ($created > 0) {
            $this->info("Created {$created} recurring chore task(s).");
        }

        return self::SUCCESS;
    }
}
