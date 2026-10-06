<?php

namespace App\Services;

use App\Models\BoardColumn;
use App\Models\Shop;
use App\Models\Task;

/**
 * Turns one config/task_templates.php entry plus one linked Shopify object into
 * a real task row — in exactly one place.
 *
 * Three callers depend on it, and they must not drift apart:
 *  - POST /api/tasks/bulk  → the Admin bulk action on the Orders list;
 *  - CodAutoTask           → the orders/create webhook (zero-click COD guard);
 *  - the order-page block  → one-tap "Add COD confirmation" from the block card.
 * (The board's template picker in public/js/app.js mirrors the same field names
 * client-side, because it posts a normal /api/tasks payload.)
 *
 * Guarantees inherited by every caller: title placeholders rendered, checklist
 * written as "- [ ] ..." lines (the format the SPA parses into tickable rows),
 * due date derived from the template, resource GID + admin deep link filled in,
 * task appended to the bottom of the first *open* column, plan ceiling honoured.
 */
class TaskTemplates
{
    /** The exact prefix public/js/app.js parses (CK_RE) — do not change alone. */
    public const CK_PREFIX = '- [ ] ';

    public const CK_DONE = '- [x] ';

    /** Resource type → admin path segment, used for the deep link. */
    public const RESOURCE_PATHS = [
        'order'       => 'orders',
        'draft_order' => 'draft_orders',
        'product'     => 'products',
        'customer'    => 'customers',
        'article'     => 'articles',
    ];

    /** Resource type → GraphQL node name, used for the GID. */
    public const RESOURCE_NODES = [
        'order'       => 'Order',
        'draft_order' => 'DraftOrder',
        'product'     => 'Product',
        'customer'    => 'Customer',
        'article'     => 'Article',
    ];

    // ---------------- lookup ----------------

    public static function all(): array
    {
        return config('task_templates') ?: [];
    }

    public static function find(string $key): ?array
    {
        $tpl = self::all()[$key] ?? null;

        return is_array($tpl) ? $tpl : null;
    }

    /** Valid values for the `template` field of POST /api/tasks/bulk. */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Templates that make sense for a given page. When no page context is
     * supplied everything is fair game; with one, the template has to be built
     * FOR that object type. Templates with resource_type null (the weekly COD
     * remittance check) are deliberately NOT offered on a resource page: "one
     * chore per shop, once a week" becomes thirty duplicate chores when it is
     * offered next to a 30-order bulk selection.
     */
    public static function forResource(?string $resourceType): array
    {
        $all = self::all();
        if (!$resourceType) {
            return $all;
        }

        return collect($all)
            ->filter(fn ($tpl) => ($tpl['resource_type'] ?? null) === $resourceType)
            ->all();
    }

    // ---------------- rendering ----------------

    /**
     * "{order}" / "{date}" placeholders, same as the SPA's fillTemplateTitle().
     * Admin bulk selections only carry GIDs (no order number), so when the
     * caller has no title we fall back to "order 5123456789" — ugly but honest
     * and searchable, unlike guessing that the GID tail is the order number.
     */
    public static function renderTitle(array $tpl, ?string $resourceTitle, ?int $resourceId = null, ?string $type = null): string
    {
        $pattern = (string) ($tpl['title'] ?? '');
        $name = (string) ($tpl['name'] ?? 'New task');

        if ($pattern === '') {
            return $name;
        }

        $label = $resourceTitle ?: ($resourceId ? ($type ?: 'order').' '.$resourceId : '(order)');

        $title = trim(preg_replace('/\s+/', ' ', str_replace(
            ['{order}', '{date}'],
            [$label, now()->format('d M Y')],
            $pattern
        )));

        return $title !== '' ? $title : $name;
    }

    /** Template checklist → the description body the board renders as ticks. */
    public static function checklist(array $tpl): string
    {
        return collect($tpl['checklist'] ?? [])
            ->map(fn ($item) => self::CK_PREFIX.$item)
            ->implode("\n");
    }

    /**
     * Reverse of checklist(): "- [ ] step" lines → [{line, text, done}].
     * null when the description holds no checklist (so callers can skip the UI).
     */
    public static function parseChecklist(?string $desc): ?array
    {
        if (!$desc) {
            return null;
        }

        $lines = explode("\n", $desc);
        $items = [];

        foreach ($lines as $i => $line) {
            if (preg_match('/^- \[( |x|X)\] (.*)$/', $line, $m)) {
                $items[] = [
                    'line' => $i,
                    'done' => strtolower($m[1]) === 'x',
                    'text' => $m[2],
                ];
            }
        }

        return $items ? ['lines' => $lines, 'items' => $items] : null;
    }

    // ---------------- resource helpers ----------------

    public static function gidFor(string $type, int $id): string
    {
        return 'gid://shopify/'.(self::RESOURCE_NODES[$type] ?? 'Node').'/'.$id;
    }

    public static function adminUrl(Shop $shop, string $type, int $id): string
    {
        return $shop->adminBaseUrl().'/'.(self::RESOURCE_PATHS[$type] ?? 'orders').'/'.$id;
    }

    // ---------------- plan helpers ----------------

    /** 0 means unlimited (paid plans). */
    public static function taskLimit(Shop $shop): int
    {
        return (int) ($shop->planConfig()['task_limit'] ?? 0);
    }

    public static function openTaskCount(Shop $shop): int
    {
        return $shop->tasks()->whereNull('completed_at')->count();
    }

    public static function limitReached(Shop $shop): bool
    {
        $limit = self::taskLimit($shop);

        return $limit > 0 && self::openTaskCount($shop) >= $limit;
    }

    /** Columns the merchant can drop bulk-created tasks into (Done excluded). */
    public static function openColumns(Shop $shop): array
    {
        return $shop->columns()->where('is_done_stage', false)->orderBy('position')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])
            ->all();
    }

    public static function columnFor(Shop $shop, ?int $columnId = null): ?BoardColumn
    {
        if ($columnId) {
            $column = $shop->columns()->where('id', $columnId)->first();
            if ($column) {
                return $column;
            }
        }

        return $shop->columns()->where('is_done_stage', false)->orderBy('position')->first()
            ?? $shop->columns()->orderBy('position')->first();
    }

    // ---------------- creation ----------------

    /**
     * $resource: ['type' => 'order', 'id' => 1042, 'title' => '#1042', 'gid' => 'gid://...']
     * $overrides: any of title / description / priority / due_at / assignee_id.
     *
     * Returns null when the shop has no column to file the task under — callers
     * decide whether that is an error worth reporting.
     */
    public static function create(Shop $shop, array $tpl, ?array $resource = null, array $overrides = [], string $actor = 'TaskPe'): ?Task
    {
        $column = self::columnFor($shop, isset($overrides['column_id']) ? (int) $overrides['column_id'] : null);
        if (!$column) {
            return null;
        }

        return $shop->tasks()->create(self::attributes($shop, $tpl, $resource, $column, $overrides, $actor));
    }

    /** The full create-array. Split out so callers can dry-run or customise. */
    public static function attributes(Shop $shop, array $tpl, ?array $resource, BoardColumn $column, array $overrides = [], string $actor = 'TaskPe'): array
    {
        $type = $resource['type'] ?? ($tpl['resource_type'] ?? null);
        $id = isset($resource['id']) ? (int) $resource['id'] : null;
        if (!$type || !$id) {
            $type = $id = null;
        }

        $dueHours = array_key_exists('due_in_hours', $overrides)
            ? $overrides['due_in_hours']
            : (int) ($tpl['due_in_hours'] ?? 24);

        $description = $overrides['description'] ?? (self::checklist($tpl) ?: null);

        return [
            'column_id'       => $column->id,
            'title'           => $overrides['title'] ?? self::renderTitle($tpl, $resource['title'] ?? null, $id, $type),
            'description'     => is_string($description) && $description !== '' ? $description : null,
            'priority'        => $overrides['priority'] ?? ($tpl['priority'] ?? 'medium'),
            'due_at'          => $overrides['due_at'] ?? ($dueHours > 0 ? now()->addHours((int) $dueHours) : null),
            'assignee_id'     => $overrides['assignee_id'] ?? null,
            'resource_type'   => $type,
            'resource_id'     => $id,
            'resource_gid'    => $resource['gid'] ?? ($type && $id ? self::gidFor($type, $id) : null),
            'resource_title'  => $resource['title'] ?? null,
            'resource_url'    => $resource['url'] ?? ($type && $id ? self::adminUrl($shop, $type, $id) : null),
            'position'        => ((int) $shop->tasks()->where('column_id', $column->id)->max('position')) + 1,
            'created_by_name' => $actor,
            'completed_at'    => $column->is_done_stage ? now() : null,
        ];
    }

    /**
     * Already-open twin of this template+resource pair? The bulk action is meant
     * to be safe to hammer: ticking the same orders twice must not double-file.
     */
    public static function openDuplicate(Shop $shop, array $tpl, string $type, int $id, ?string $resourceTitle = null, ?string $title = null): bool
    {
        return $shop->tasks()
            ->where('resource_type', $type)
            ->where('resource_id', $id)
            ->where('title', $title ?: self::renderTitle($tpl, $resourceTitle, $id, $type))
            ->whereNull('completed_at')
            ->exists();
    }
}
