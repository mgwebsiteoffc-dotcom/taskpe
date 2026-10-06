<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Support\ShopContext;

class BoardController extends Controller
{
    /** GET /api/board — everything the SPA needs to render in one call. */
    public function show(ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $columns = $shop->columns()->with(['tasks.assignee'])->get()->map(fn ($col) => [
            'id'            => $col->id,
            'name'          => $col->name,
            'position'      => $col->position,
            'is_done_stage' => $col->is_done_stage,
            'tasks'         => $col->tasks->map(fn (Task $t) => $this->taskJson($t))->values(),
        ])->values();

        return response()->json([
            'shop' => [
                'domain'   => $shop->domain,
                'name'     => $shop->name,
                'plan'     => $shop->plan,
                'plan_cfg' => $shop->planConfig(),
                'timezone' => $shop->timezone,
                'currency' => $shop->currency ?: config('shopify.billing_fallback_currency', 'USD'),
                'onboarded' => !is_null($shop->setting('onboarded_at')),   // first-run tour
            ],
            'plans' => config('shopify.plans'),   // localized price table for the Plan tab
            'task_templates' => config('task_templates'),   // COD/NDR one-click checklist pack
            'columns' => $columns,
            'members' => $shop->members()->orderBy('name')->get()->map(fn ($m) => [
                'id'                => $m->id,
                'name'              => $m->name,
                'initials'          => $m->initials(),
                'phone'             => $m->phone,
                'role'              => $m->role,
                'active'            => $m->active,
                'whatsapp_verified' => $m->whatsapp_verified,
                'portal_active'     => $m->portalActive(),
            ])->values(),
            'me'       => $ctx->actor()?->id,
            'whatsapp' => [
                'plan_allowed' => (bool) ($shop->planConfig()['whatsapp'] ?? false),
                'master_on'    => $shop->whatsappMasterOn(),
                'has_key'      => !empty($shop->whatify_api_key),
                'enabled'      => $shop->whatsappEnabled(),
            ],
        ]);
    }

    public static function taskJson(Task $t): array
    {
        return [
            'id'             => $t->id,
            'column_id'      => $t->column_id,
            'title'          => $t->title,
            'description'    => $t->description,
            'priority'       => $t->priority,
            'due_at'         => $t->due_at?->toIso8601String(),
            'overdue'        => $t->isOverdue(),
            'position'       => $t->position,
            'completed_at'   => $t->completed_at?->toIso8601String(),
            'created_at'     => $t->created_at?->toIso8601String(),
            'created_by'     => $t->created_by_name,
            'assignee'       => $t->assignee ? [
                'id'       => $t->assignee->id,
                'name'     => $t->assignee->name,
                'initials' => $t->assignee->initials(),
            ] : null,
            'resource' => $t->resource_type ? [
                'type'  => $t->resource_type,
                'id'    => $t->resource_id,
                'gid'   => $t->resource_gid,
                'label' => $t->resourceLabel(),
                'title' => $t->resource_title,
                'url'   => $t->resource_url,
            ] : null,
        ];
    }
}
