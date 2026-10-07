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

/*
| Admin API token maintenance (expiring offline tokens).
| Requests renew the hour-long access token by themselves, so this pass exists for the
| stores nobody opens: the refresh token that makes renewal possible lives about 90 days,
| and a merchant who takes a quarter off would come back to "reconnect me" for no reason of
| theirs. Only stores inside 14 days of that are touched, so the call volume is a rounding
| error. Converts a legacy non-expiring token on the way past, which is how an old install
| survives the platform change without an uninstall.
*/
Schedule::command('taskpe:tokens --rotate')->dailyAt('03:40')->withoutOverlapping();
