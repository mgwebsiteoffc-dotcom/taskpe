<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendWhatsAppJob;
use App\Models\BoardColumn;
use App\Models\Member;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Support\ShopContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends Controller
{
    /** POST /api/tasks */
    public function store(Request $request, ShopContext $ctx)
    {
        $shop = $ctx->shop();

        $limit = (int) ($shop->planConfig()['task_limit'] ?? 0);
        if ($limit > 0 && $shop->tasks()->whereNull('completed_at')->count() >= $limit) {
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

    // ---------------- internals ----------------

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
