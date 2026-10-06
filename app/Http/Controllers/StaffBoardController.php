<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Api\BoardController;
use App\Models\Task;
use App\Support\ShopContext;

/**
 * GET /staff/api/board — same shape as the admin board payload, minus the
 * sensitive bits: no colleague phone numbers, no WhatsApp state, no shop
 * domain, and onboarded is forced true (the tour is an admin thing).
 */
class StaffBoardController extends Controller
{
    public function show(ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $columns = $shop->columns()
            ->with(['tasks' => fn ($q) => $q->with('assignee')->orderBy('position')])
            ->orderBy('position')
            ->get()
            ->map(fn ($col) => [
                'id'            => $col->id,
                'name'          => $col->name,
                'is_done_stage' => $col->is_done_stage,
                'tasks'         => $col->tasks->map(fn (Task $t) => BoardController::taskJson($t))->values(),
            ]);

        return response()->json([
            'shop' => [
                'name'      => $shop->name,
                'plan'      => $shop->plan,
                'plan_cfg'  => $shop->planConfig(),
                'timezone'  => $shop->timezone,
                'currency'  => $shop->currency ?: config('shopify.billing_fallback_currency', 'USD'),
                'onboarded' => true,
            ],
            'plans' => config('shopify.plans'),
            'task_templates' => config('task_templates'),
            'columns' => $columns,
            'members' => $shop->members()->orderBy('name')->get()->map(fn ($m) => [
                'id'                => $m->id,
                'name'              => $m->name,
                'initials'          => $m->initials(),
                // no phone — teammates don't need each other's numbers
                'role'              => $m->role,
                'active'            => $m->active,
                'whatsapp_verified' => $m->whatsapp_verified,
                'portal_active'     => $m->portalActive(),
            ])->values(),
            'me' => $ctx->actor()?->id,
            'whatsapp' => [   // neutral — kills all admin nag banners
                'plan_allowed' => false,
                'master_on'    => false,
                'has_key'      => false,
                'enabled'      => false,
            ],
        ]);
    }
}
