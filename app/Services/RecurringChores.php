<?php

namespace App\Services;

use App\Models\Shop;

/**
 * Auto-recreate recurring chores from the template pack. Today that's the
 * weekly COD remittance reconciliation (Settings → COD / NDR automation,
 * OFF by default) — the task that quietly saves thousands in unclaimed COD.
 *
 * Runs daily via the one cron entry (`taskpe:recurring-chores`). Idempotent:
 * a shop gets at most one OPEN weekly chore from this service per 7 days —
 * complete it and next week's copy appears; ignore it and it won't pile up.
 */
class RecurringChores
{
    public const ACTOR = 'TaskPe Auto (Weekly)';
    public const TEMPLATE = 'cod-remittance';
    public const WINDOW_DAYS = 7;

    public function run(): int
    {
        $created = 0;

        Shop::query()
            ->whereNull('uninstalled_at')
            ->whereNotNull('access_token')
            ->chunkById(50, function ($shops) use (&$created) {
                foreach ($shops as $shop) {
                    $created += $this->maybeCreateFor($shop) ? 1 : 0;
                }
            });

        return $created;
    }

    public function maybeCreateFor(Shop $shop): ?\App\Models\Task
    {
        if (!$shop->setting('automation.weekly_remittance', false)) {
            return null;
        }

        $tpl = config('task_templates.'.self::TEMPLATE);
        if (!$tpl) {
            return null;
        }

        // Already an open copy from the last 7 days? Do nothing.
        $hasOpen = $shop->tasks()
            ->where('created_by_name', self::ACTOR)
            ->whereNull('completed_at')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->exists();
        if ($hasOpen) {
            return null;
        }

        $column = $shop->columns()->where('is_done_stage', false)->orderBy('position')->first()
            ?? $shop->columns()->orderBy('position')->first();
        if (!$column) {
            return null;
        }

        return $shop->tasks()->create([
            'column_id'       => $column->id,
            'title'           => str_replace('{date}', now()->format('d M Y'), $tpl['title']),
            'description'     => CodAutoTask::checklist($tpl),
            'priority'        => $tpl['priority'] ?? 'medium',
            'due_at'          => now()->addHours((int) ($tpl['due_in_hours'] ?? 168)),
            'position'        => ((int) $shop->tasks()->where('column_id', $column->id)->max('position')) + 1,
            'created_by_name' => self::ACTOR,
        ]);
    }
}
