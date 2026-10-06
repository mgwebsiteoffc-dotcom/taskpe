<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| One cron entry on shared hosting runs everything:
|   * * * * * cd /home/USER/taskpe && php artisan schedule:run >> /dev/null 2>&1
|--------------------------------------------------------------------------
*/

// Drain the database queue (WhatsApp sends, webhook processing).
Schedule::command('queue:work', ['--stop-when-empty', '--tries=2', '--timeout=60'])
    ->everyMinute()
    ->withoutOverlapping();

// Owner "Bird's Eye View" digests (each shop has its own send time + timezone).
Schedule::command('taskpe:send-digests')->everyMinute();

// Weekly COD remittance chores (idempotent — safe to run daily).
Schedule::command('taskpe:recurring-chores')->dailyAt('09:05');

// Housekeeping.
Schedule::command('taskpe:prune')->daily();
