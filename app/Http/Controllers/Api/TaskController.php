<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppJob;
use App\Models\BoardColumn;
use App\Models\Member;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Services\ShopifyClient;
use App\Services\TaskTemplates;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /** POST /api/tasks */
    public function store(Request $request, ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $limit = TaskTemplates::taskLimit($shop);
        if ($limit > 0 && TaskTemplates::openTaskCount($shop) >= $limit) {
            return response()->json(['error' => 'plan_limit', 'message' => "Free plan allows {$limit} open tasks. Upgrade to add more."], 402);
        }

        $data = $request->validate($this->rules($shop));
        $data = $this->normalizeResource($shop, $data);

        $column = $this->findColumn($ctx, (int) $data['column_id']);

        $task = $shop->tasks()->create([
            ...$data,
            'position'        => ((int) $shop->tasks()->where('column_id', $column->id)->max('position')) + 1,
            'created_by_name' => $ctx->actorName(),
            'completed_at'    => $column->is_done_stage ? now() : null,
        ]);

        TaskActivity::record($task, 'created', ['column' => $column->name], $ctx->actorName());
        $this->maybeNotifyAssignee($task, $ctx);

        return response()->json(BoardController::taskJson($task->load('assignee')), 201);
    }

    /** PATCH /api/tasks/{id} */
    public function update(Request $request, ShopContext $ctx, int $id)
    {
        $shop = $ctx->shop();
        $task = $this->findTask($ctx, $id);

        $data = $request->validate($this->rules($shop, partial: true));
        if (array_key_exists('resource_type', $data)) {
            $data = $this->normalizeResource($shop, $data);
        }

        $oldAssignee = $task->assignee_id;
        $task->update($data);

        if ($request->has('assignee_id') && (int) $task->assignee_id !== (int) $oldAssignee) {
            $name = $task->assignee?->name ?? 'Unassigned';
            TaskActivity::record($task, 'assigned', ['to' => $name], $ctx->actorName());
            $this->maybeNotifyAssignee($task, $ctx);
        } else {
            TaskActivity::record($task, 'updated', [], $ctx->actorName());
        }

        return response()->json(BoardController::taskJson($task->fresh('assignee')));
    }

    /** POST /api/tasks/{id}/move { column_id, position } */
    public function move(Request $request, ShopContext $ctx, int $id)
    {
        $task = $this->findTask($ctx, $id);

        $data = $request->validate([
            'column_id' => ['required', 'integer'],
            'position'  => ['integer', 'min:0'],
        ]);

        $column = $this->findColumn($ctx, (int) $data['column_id']);
        $fromDone = $task->isDone();
        $oldColumnId = (int) $task->column_id;

        $task->update([
            'column_id'    => $column->id,
            'position'     => (int) ($data['position'] ?? 999999),
            'completed_at' => $column->is_done_stage ? ($task->completed_at ?? now()) : null,
        ]);

        $this->reindex($column);

        if ((int) $column->id !== $oldColumnId) {
            TaskActivity::record($task, 'moved', ['to' => $column->name], $ctx->actorName());
        }
        if (!$fromDone && $task->isDone()) {
            TaskActivity::record($task, 'completed', [], $ctx->actorName());
        } elseif ($fromDone && !$task->isDone()) {
            TaskActivity::record($task, 'reopened', [], $ctx->actorName());
        }

        return response()->json(BoardController::taskJson($task->fresh('assignee')));
    }

    /** POST /api/tasks/{id}/complete  (toggle) */
    public function complete(ShopContext $ctx, int $id)
    {
        $task   = $this->findTask($ctx, $id);
        $doneCol = $ctx->shop()->columns()->where('is_done_stage', true)->first()
            ?? $ctx->shop()->columns()->orderByDesc('position')->first();
        $firstCol = $ctx->shop()->columns()->where('is_done_stage', false)->orderBy('position')->first()
            ?? $ctx->shop()->columns()->orderBy('position')->first();

        if ($task->isDone()) {
            $task->update(['column_id' => $firstCol->id, 'completed_at' => null, 'position' => 999999]);
            $this->reindex($firstCol);
            TaskActivity::record($task, 'reopened', ['to' => $firstCol->name], $ctx->actorName());
        } else {
            $task->update(['column_id' => $doneCol->id, 'completed_at' => now(), 'position' => 999999]);
            $this->reindex($doneCol);
            TaskActivity::record($task, 'completed', ['to' => $doneCol->name], $ctx->actorName());
        }

        return response()->json(BoardController::taskJson($task->fresh('assignee')));
    }

    /** POST /api/tasks/{id}/remind — ping the assignee on WhatsApp right now. */
    public function remind(ShopContext $ctx, int $id)
    {
        $task = $this->findTask($ctx, $id);

        if (!$task->assignee) {
            return response()->json(['error' => 'no_assignee', 'message' => 'Assign the task to someone first.'], 422);
        }

        if ($task->assignee->whatsapp_verified && $ctx->shop()->whatsappEnabled()) {
            SendWhatsAppJob::dispatch($ctx->id(), $task->assignee_id, 'task_reminder', $task->id);

            return response()->json(['ok' => true, 'queued' => true]);
        }

        return response()->json(['ok' => false, 'queued' => false, 'message' => 'Assignee WhatsApp not verified or WhatsApp not enabled.'], 422);
    }

    /** DELETE /api/tasks/{id} */
    public function destroy(ShopContext $ctx, int $id)
    {
        $this->findTask($ctx, $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** GET /api/tasks/{id}/activity */
    public function activity(ShopContext $ctx, int $id)
    {
        $task = $this->findTask($ctx, $id);

        return response()->json(
            $task->activities()->limit(50)->get()->map(fn ($a) => [
                'action'     => $a->action,
                'actor'      => $a->actor_name,
                'meta'       => $a->meta,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values()
        );
    }

    /**
     * POST /api/tasks/bulk — one template × many Shopify objects.
     *
     * This is the engine behind "Create TaskPe tasks" on the Orders list
     * (admin.order-index.selection-action.render): tick 30 COD orders, pick
     * "COD confirmation", and every one of them gets a pre-filled task with the
     * same checklist, assignee and due rule — instead of 30 × (open order →
     * More actions → Create task → type → save).
     *
     * Safe to re-run: an order that already has an OPEN task with the same
     * rendered title is skipped, never doubled. The plan's open-task ceiling
     * still applies, so the batch stops at the limit instead of blowing past it.
     */
    public function bulk(Request $request, ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $data = $request->validate([
            'template'          => ['required', 'string', Rule::in(TaskTemplates::keys())],
            'resources'         => ['required', 'array', 'min:1', 'max:100'],
            'resources.*.type'  => ['required', Rule::in(Task::RESOURCE_TYPES)],
            'resources.*.id'    => ['required', 'integer', 'min:1'],
            'resources.*.title' => ['nullable', 'string', 'max:190'],
            'resources.*.gid'   => ['nullable', 'string', 'max:120'],
            'assignee_id'       => ['nullable', 'integer', Rule::exists('members', 'id')->where('shop_id', $shop->id)->where('active', true)],
            'column_id'         => ['nullable', 'integer', Rule::exists('columns', 'id')->where('shop_id', $shop->id)],
            'priority'          => ['nullable', Rule::in(Task::PRIORITIES)],
            'due_in_hours'      => ['nullable', 'integer', 'min:1', 'max:2160'],
            'notify'            => ['boolean'],
        ]);

        $tpl = TaskTemplates::find($data['template']);
        if (!$tpl) {
            return response()->json(['error' => 'unknown_template', 'message' => 'Unknown task template.'], 422);
        }

        $limit = TaskTemplates::taskLimit($shop);
        if ($limit > 0 && TaskTemplates::openTaskCount($shop) >= $limit) {
            return response()->json(['error' => 'plan_limit', 'message' => "Free plan allows {$limit} open tasks. Upgrade to add more."], 402);
        }

        $tplType = $tpl['resource_type'] ?? null;
        $resources = collect($data['resources'])
            ->map(fn ($r) => [
                'type'  => $r['type'],
                'id'    => (int) $r['id'],
                'title' => $r['title'] ?? null,
                'gid'   => $r['gid'] ?? null,
            ])
            // Dedupe within the batch itself (same order ticked twice by accident).
            ->unique(fn ($r) => $r['type'].':'.$r['id'])
            ->values();

        // Bulk selections arrive as GIDs only — no order number. Fill the titles
        // in ourselves so tasks read "Confirm COD order #1042", not "order 5123…".
        $resources = $this->hydrateOrderTitles($shop, $resources);

        $overrides = array_filter([
            'priority'     => $data['priority'] ?? null,
            'due_in_hours' => $data['due_in_hours'] ?? null,
            'assignee_id'  => $data['assignee_id'] ?? null,
            'column_id'    => $data['column_id'] ?? null,
        ], fn ($v) => $v !== null);

        $actor = $ctx->actorName();
        $created = [];
        $skipped = [];
        $limited = false;

        // WhatsApp pings are capped per batch on purpose: one message per task
        // would mean 30 pings for one click (and a Meta template-throttle visit).
        $pingsLeft = !empty($data['notify']) && !empty($overrides['assignee_id']) ? 3 : 0;

        foreach ($resources as $res) {
            if ($tplType && $res['type'] !== $tplType) {
                $skipped[] = ['id' => $res['id'], 'reason' => 'wrong_resource', 'message' => "This template files against a {$tplType}."];

                continue;
            }

            if ($limit > 0 && TaskTemplates::openTaskCount($shop) >= $limit) {
                $limited = true;
                break;
            }

            $title = TaskTemplates::renderTitle($tpl, $res['title'], $res['id'], $res['type']);

            if (TaskTemplates::openDuplicate($shop, $tpl, $res['type'], $res['id'], $res['title'], $title)) {
                $skipped[] = ['id' => $res['id'], 'reason' => 'already_open', 'message' => 'An open task for this already exists.'];

                continue;
            }

            $task = TaskTemplates::create($shop, $tpl, $res, [...$overrides, 'title' => $title], $actor);
            if (!$task) {
                $skipped[] = ['id' => $res['id'], 'reason' => 'no_column', 'message' => 'Add a board column first.'];

                continue;
            }

            TaskActivity::record($task, 'created', ['template' => $data['template'], 'bulk' => true], $actor);

            if ($pingsLeft > 0) {
                $this->maybeNotifyAssignee($task, $ctx);
                $pingsLeft--;
            }

            $created[] = BoardController::taskJson($task->load('assignee'));
        }

        return response()->json([
            'created'  => count($created),
            'skipped'  => $skipped,
            'limited'  => $limited,
            'template' => $data['template'],
            'tasks'    => $created,
            'plan'     => ['open' => TaskTemplates::openTaskCount($shop), 'max' => $limit],
            'message'  => $limited
                ? 'Stopped at your plan\'s open-task limit — upgrade to queue more.'
                : (count($created) ? count($created).' task(s) created.' : 'Nothing to create — tasks already existed.'),
        ], 201);
    }

    /**
     * GET /api/task-templates?type=order — the picker data for the extensions.
     * The board already ships templates inside /api/board; extensions want the
     * short version filtered to the page they are standing on.
     */
    public function templates(Request $request, ShopContext $ctx)
    {
        $data = $request->validate([
            'type' => ['nullable', Rule::in(Task::RESOURCE_TYPES)],
        ]);

        $templates = TaskTemplates::forResource($data['type'] ?? null);

        return response()->json([
            'templates' => collect($templates)->map(fn ($tpl, $key) => [
                'key'          => $key,
                'icon'         => $tpl['icon'] ?? 'box',
                'name'         => $tpl['name'],
                'tagline'      => $tpl['tagline'] ?? null,
                'priority'     => $tpl['priority'] ?? 'medium',
                'due_in_hours' => $tpl['due_in_hours'] ?? null,
                'resource_type' => $tpl['resource_type'] ?? null,
                'title'        => $tpl['title'] ?? null,
                'steps'        => count($tpl['checklist'] ?? []),
                'checklist'    => array_values($tpl['checklist'] ?? []),
            ])->values(),
            'columns'   => TaskTemplates::openColumns($ctx->shop()),
            'members'   => $ctx->shop()->members()->where('active', true)->orderBy('name')
                ->get()->map(fn ($m) => ['id' => $m->id, 'name' => $m->name]),
            'plan'      => [
                'open' => TaskTemplates::openTaskCount($ctx->shop()),
                'max'  => TaskTemplates::taskLimit($ctx->shop()),
                'full' => TaskTemplates::limitReached($ctx->shop()),
            ],
        ]);
    }

    /**
     * GET /api/resource-tasks?type=order&id=1042 — everything the order-page
     * block card needs in ONE request: this order's open tasks (checklist
     * already parsed, so ticking a step is one PATCH away) and its recently
     * completed ones, the templates that fit this object type, a deep link back
     * to the board, and whether the plan still has room to file more.
     */
    public function resourceTasks(Request $request, ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $data = $request->validate([
            'type' => ['required', Rule::in(Task::RESOURCE_TYPES)],
            'id'   => ['required', 'integer', 'min:1'],
            'done' => ['boolean'],   // include recently completed ones too
        ]);

        // Two small queries instead of one filtered list: taking "newest 30 and
        // split in PHP" silently loses older OPEN tasks once an order has enough
        // completed ones, which is exactly when the card matters most.
        $shape = function (Task $t) use ($shop) {
            $json = BoardController::taskJson($t->load('assignee'));
            $ck = TaskTemplates::parseChecklist($t->description);

            return $json + [
                'checklist'  => $ck['items'] ?? [],
                'done_count' => $ck ? collect($ck['items'])->where('done', true)->count() : 0,
                'step_count' => $ck ? count($ck['items']) : 0,
                'open_url'   => $shop->appUrl('task='.$t->id),
            ];
        };

        $forResource = fn () => $shop->tasks()
            ->where('resource_type', $data['type'])
            ->where('resource_id', (int) $data['id']);

        $open = $forResource()->whereNull('completed_at')
            ->orderByRaw('due_at is null, due_at asc')
            ->limit(10)->get()->map($shape)->values();

        $done = $forResource()->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->limit(5)->get();

        $doneCount = $forResource()->whereNotNull('completed_at')->count();

        return response()->json([
            'resource' => [
                'type' => $data['type'],
                'id'   => (int) $data['id'],
                'gid'  => TaskTemplates::gidFor($data['type'], (int) $data['id']),
                'url'  => TaskTemplates::adminUrl($shop, $data['type'], (int) $data['id']),
            ],
            'open'       => $open,
            'done'       => !empty($data['done']) ? $done->map($shape)->values() : [],
            'done_count' => $doneCount,
            'templates'  => collect(TaskTemplates::forResource($data['type']))
                ->map(fn ($tpl, $key) => [
                    'key'       => $key,
                    'icon'      => $tpl['icon'] ?? 'box',
                    'name'      => $tpl['name'],
                    'priority'  => $tpl['priority'] ?? 'medium',
                    'steps'     => count($tpl['checklist'] ?? []),
                ])->values(),
            'board_url'  => $shop->appUrl(),
            'plan'       => [
                'open' => TaskTemplates::openTaskCount($shop),
                'max'  => TaskTemplates::taskLimit($shop),
                'full' => TaskTemplates::limitReached($shop),
            ],
        ]);
    }

    // ---------------- internals ----------------

    /**
     * Admin bulk selection hands us GIDs and nothing else, so fetch the order
     * names in ONE query (best-effort: if Shopify is slow we just create tasks
     * with "order <id>" titles instead of failing the whole batch).
     */
    protected function hydrateOrderTitles(Shop $shop, $resources)
    {
        $ids = $resources->filter(fn ($r) => $r['type'] === 'order' && empty($r['title']))
            ->pluck('id')->unique()->take(50)->values();

        if ($ids->isEmpty()) {
            return $resources;
        }

        try {
            $query = 'id:'.implode(' OR id:', $ids->all());
            $gql = <<<'GQL'
            query ($query: String!) {
              orders(first: 50, query: $query) { nodes { id name } }
            }
            GQL;

            $data = (new ShopifyClient($shop))->graphql($gql, ['query' => $query]);

            $names = collect($data['orders']['nodes'] ?? [])
                ->mapWithKeys(fn ($o) => [(int) basename($o['id']) => $o['name']]);

            if ($names->isEmpty()) {
                return $resources;
            }

            return $resources->map(function ($r) use ($names) {
                if ($r['type'] === 'order' && empty($r['title']) && $names->has($r['id'])) {
                    $r['title'] = $names->get($r['id']);
                }

                return $r;
            })->values();
        } catch (\Throwable $e) {
            return $resources;
        }
    }

    protected function rules($shop, bool $partial = false): array
    {
        $req = $partial ? 'sometimes' : 'required';
        $memberExists = Rule::exists('members', 'id')->where('shop_id', $shop->id)->where('active', true);
        $columnExists = Rule::exists('columns', 'id')->where('shop_id', $shop->id);

        return [
            'title'          => [$req, 'string', 'max:190'],
            'description'    => ['nullable', 'string', 'max:5000'],
            'priority'       => [$partial ? 'sometimes' : 'sometimes', Rule::in(Task::PRIORITIES)],
            'due_at'         => ['nullable', 'date'],
            'assignee_id'    => ['nullable', 'integer', $memberExists],
            'column_id'      => [$req, 'integer', $columnExists],
            'resource_type'  => ['nullable', Rule::in(Task::RESOURCE_TYPES)],
            'resource_id'    => ['nullable', 'integer', 'required_with:resource_type'],
            'resource_gid'   => ['nullable', 'string', 'max:120'],
            'resource_title' => ['nullable', 'string', 'max:190'],
            'resource_url'   => ['nullable', 'url', 'max:512', 'starts_with:https://admin.shopify.com,https://'],
        ];
    }

    /** Drop all resource fields when type is cleared; coerce id to int. */
    protected function normalizeResource($shop, array $data): array
    {
        if (empty($data['resource_type'])) {
            $data['resource_type'] = $data['resource_id'] = $data['resource_gid'] = $data['resource_title'] = $data['resource_url'] = null;

            return $data;
        }

        if (!empty($data['resource_id']) && empty($data['resource_gid'])) {
            $map = [
                'order' => 'Order', 'draft_order' => 'DraftOrder', 'product' => 'Product',
                'customer' => 'Customer', 'article' => 'Article',
            ];
            $data['resource_gid'] = 'gid://shopify/'.($map[$data['resource_type']] ?? 'Node').'/'.$data['resource_id'];
        }

        if (empty($data['resource_url']) && !empty($data['resource_id'])) {
            $path = ['order' => 'orders', 'draft_order' => 'draft_orders', 'product' => 'products', 'customer' => 'customers', 'article' => 'articles'][$data['resource_type']];
            $data['resource_url'] = $shop->adminBaseUrl().'/'.$path.'/'.$data['resource_id'];
        }

        return $data;
    }

    protected function maybeNotifyAssignee(Task $task, ShopContext $ctx): void
    {
        if (!$task->assignee_id) {
            return;
        }

        $member = Member::find($task->assignee_id);

        // Only WhatsApp-verified staff get pings — the OTP flow doubles as
        // opt-in proof, which keeps us on the right side of Meta policy.
        if ($member && $member->whatsapp_verified && $ctx->shop()->whatsappEnabled()) {
            SendWhatsAppJob::dispatch($ctx->id(), $member->id, 'task_assigned', $task->id);
        }
    }

    /** Re-number positions 0..n inside a column (cheap for board sizes here). */
    protected function reindex(BoardColumn $column): void
    {
        $tasks = $column->tasks()->orderBy('position')->orderBy('id')->pluck('id');
        foreach ($tasks as $i => $taskId) {
            Task::where('id', $taskId)->update(['position' => $i]);
        }
    }

    protected function findTask(ShopContext $ctx, int $id): Task
    {
        return $ctx->shop()->tasks()->where('id', $id)->firstOrFail();
    }

    protected function findColumn(ShopContext $ctx, int $id): BoardColumn
    {
        return $ctx->shop()->columns()->where('id', $id)->firstOrFail();
    }
}
