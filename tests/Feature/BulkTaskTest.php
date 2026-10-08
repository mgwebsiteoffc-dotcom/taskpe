<?php

namespace Tests\Feature;

use App\Models\BoardColumn;
use App\Models\Member;
use App\Models\Shop;
use App\Models\Task;
use App\Services\TaskTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\MintsSessionToken;
use Tests\TestCase;

/**
 * The Orders-list bulk action (admin.order-index.selection-action.render →
 * POST /api/tasks/bulk) is the difference between "tick 30 COD orders, one
 * click" and 30 × open → More actions → Create task → type → save. Three
 * properties matter and none of them are visible in the happy path:
 *
 *  1. one linked, checklist-bearing task PER order — and ONE admin query for
 *     the whole batch, not one per order (rate limits, and patience);
 *  2. pressing it twice must not double-file the same follow-up;
 *  3. the plan's open-task ceiling still stops the batch mid-way.
 */
class BulkTaskTest extends TestCase
{
    use RefreshDatabase;
    use MintsSessionToken;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'domain'       => 'demo-store.myshopify.com',
            'handle'       => 'demo-store',
            'name'         => 'Demo Store',
            'access_token' => 'shpat_test',
            'plan'         => 'starter',
            'installed_at' => now(),
        ]);

        BoardColumn::create(['shop_id' => $this->shop->id, 'name' => 'To Do', 'position' => 0, 'is_done_stage' => false]);
        BoardColumn::create(['shop_id' => $this->shop->id, 'name' => 'Done', 'position' => 1, 'is_done_stage' => true]);

        // Bulk selections arrive as GIDs only, so the order numbers are fetched
        // from the Admin API. Fake the whole API, not a per-order call.
        Http::fake([
            '*graphql.json' => Http::response(['data' => ['orders' => ['nodes' => [
                ['id' => 'gid://shopify/Order/1042', 'name' => '#1042'],
                ['id' => 'gid://shopify/Order/1043', 'name' => '#1043'],
            ]]]]),
        ]);
    }

    public function test_one_linked_task_is_filed_per_selected_order(): void
    {
        $response = $this->withSessionToken()->postJson('/api/tasks/bulk', $this->payload());

        $response->assertStatus(201)->assertJsonPath('created', 2)->assertJsonPath('limited', false);

        $this->assertSame(2, Task::count());

        $task = Task::orderBy('id')->first();
        $this->assertSame('order', $task->resource_type);
        $this->assertSame(1042, (int) $task->resource_id);
        $this->assertSame('#1042', $task->resource_title);
        $this->assertSame('Confirm COD order #1042', $task->title);
        $this->assertSame('gid://shopify/Order/1042', $task->resource_gid);
        $this->assertStringContainsString(
            'https://admin.shopify.com/store/demo-store/orders/1042',
            $task->resource_url
        );
        // The template's steps travel with it — that is the whole point.
        $this->assertSame(6, substr_count($task->description, '- [ ] '));
        $this->assertSame('high', $task->priority);
        $this->assertNotNull($task->due_at);
        $this->assertFalse($task->isDone());

        // 30 orders must not mean 30 admin queries.
        Http::assertSentCount(1);
    }

    public function test_pressing_it_again_skips_what_is_already_open(): void
    {
        $this->withSessionToken()->postJson('/api/tasks/bulk', $this->payload())->assertJsonPath('created', 2);

        $again = $this->withSessionToken()->postJson('/api/tasks/bulk', $this->payload());

        $again->assertStatus(201)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('skipped.0.reason', 'already_open');

        $this->assertSame(2, Task::count(), 'a re-run must never double-file a follow-up');
    }

    public function test_the_batch_stops_at_the_plans_open_task_ceiling(): void
    {
        config(['shopify.plans.free.task_limit' => 3]);
        $this->shop->update(['plan' => 'free']);

        $payload = $this->payload();
        $payload['resources'][] = ['type' => 'order', 'id' => 1044];
        $payload['resources'][] = ['type' => 'order', 'id' => 1045];

        // 1 open task already, ceiling 3, four selected → two get filed.
        Task::create([
            'shop_id' => $this->shop->id,
            'column_id' => BoardColumn::where('shop_id', $this->shop->id)->value('id'),
            'title' => 'Already on the board',
            'priority' => 'medium',
            'position' => 1,
        ]);

        $this->withSessionToken()->postJson('/api/tasks/bulk', $payload)
            ->assertStatus(201)
            ->assertJsonPath('created', 2)
            ->assertJsonPath('limited', true)
            ->assertJsonPath('plan.max', 3);

        $this->assertSame(3, Task::count());
    }

    public function test_a_template_built_for_orders_refuses_a_product_selection(): void
    {
        // The extension filters these out; the API must still refuse them so a
        // hand-rolled caller cannot link an order template to a product.
        $this->withSessionToken()->postJson('/api/tasks/bulk', [
            'template' => 'cod-confirm',
            'resources' => [['type' => 'product', 'id' => 7, 'title' => 'Kurti']],
        ])->assertStatus(201)
            ->assertJsonPath('created', 0)
            ->assertJsonPath('skipped.0.reason', 'wrong_resource');
    }

    public function test_an_unknown_template_is_a_validation_error_not_a_task(): void
    {
        $this->withSessionToken()->postJson('/api/tasks/bulk', [
            'template' => 'make-me-a-sandwich',
            'resources' => [['type' => 'order', 'id' => 1042]],
        ])->assertStatus(422)->assertJsonValidationErrors('template');
    }

    public function test_the_assignee_is_optional_and_tenant_scoped(): void
    {
        $otherShop = Shop::create([
            'domain' => 'other.myshopify.com', 'handle' => 'other',
            'access_token' => 'shpat_x', 'installed_at' => now(),
        ]);
        $foreignMember = Member::create([
            'shop_id' => $otherShop->id, 'name' => 'Not ours', 'phone' => '919000000001',
        ]);

        $this->withSessionToken()->postJson('/api/tasks/bulk', $this->payload([
            'assignee_id' => $foreignMember->id,
        ]))->assertStatus(422)->assertJsonValidationErrors('assignee_id');
    }

    /** What the order-page block (admin.order-details.block.render) loads. */
    public function test_resource_tasks_return_the_order_slice_of_the_board(): void
    {
        $this->withSessionToken()->postJson('/api/tasks/bulk', $this->payload())->assertJsonPath('created', 2);

        $task = Task::orderBy('id')->first();

        $response = $this->withSessionToken()->getJson('/api/resource-tasks?type=order&id=1042')
            ->assertOk()
            ->assertJsonPath('resource.id', 1042)
            ->assertJsonPath('open.0.title', 'Confirm COD order #1042')
            ->assertJsonPath('open.0.checklist.0.done', false)
            ->assertJsonPath('open.0.done_count', 0)
            ->assertJsonPath('templates.0.key', 'cod-confirm')
            ->assertJsonPath('plan.full', false);

        // Every row links straight back to the task on the board, inside admin.
        $this->assertStringContainsString('?task='.$task->id, $response->json('open.0.open_url'));
        $this->assertStringContainsString(
            'apps/'.config('shopify.api_key'),
            $response->json('board_url')
        );
    }

    public function test_a_tick_written_by_the_block_is_readable_by_the_board(): void
    {
        $this->withSessionToken()->postJson('/api/tasks/bulk', $this->payload());
        $task = Task::orderBy('id')->first();

        // The block does exactly this: flip one "- [ ]" line, PATCH the description.
        $parsed = TaskTemplates::parseChecklist($task->description);
        $lines = $parsed['lines'];
        $lines[$parsed['items'][0]['line']] = '- [x] '.$parsed['items'][0]['text'];
        $task->update(['description' => implode("\n", $lines)]);

        $this->withSessionToken()->getJson('/api/resource-tasks?type=order&id=1042')
            ->assertOk()
            ->assertJsonPath('open.0.done_count', 1)
            ->assertJsonPath('open.0.checklist.0.done', true);
    }

    // ---------------- helpers ----------------

    protected function payload(array $extra = []): array
    {
        return array_replace([
            'template' => 'cod-confirm',
            'resources' => [
                ['type' => 'order', 'id' => 1042, 'gid' => 'gid://shopify/Order/1042'],
                ['type' => 'order', 'id' => 1043, 'gid' => 'gid://shopify/Order/1043'],
            ],
        ], $extra);
    }
}
