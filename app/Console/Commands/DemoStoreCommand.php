<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Models\Shop;
use App\Services\ShopifyClient;
use Illuminate\Console\Command;

/**
 * php artisan taskpe:demo-store {shop=...} [--fresh] [--force]
 *
 * Fills a (dev) shop with a realistic Indian-D2C board: team, an extra
 * "Needs Attention" column and ~10 tasks straight from the COD/NDR pack —
 * partially-ticked checklists, an overdue NDR, an auto-COD task, a done
 * column with history. Built for App Store screenshots + review demos.
 * Idempotent via the demo_seeded_at flag; --fresh rebuilds from scratch.
 */
class DemoStoreCommand extends Command
{
    protected $signature = 'taskpe:demo-store
                            {shop? : myshopify.com domain (default: first shop)}
                            {--fresh : delete previous demo data and reseed}
                            {--force : skip the interactive confirmation}';

    protected $description = 'Seed a shop with demo team/tasks for screenshots and App Store review demos';

    private const DEMO_PHONES = ['919876500001', '919876500002', '919876500003'];
    private const ACTOR = 'TaskPe Demo';

    public function handle(): int
    {
        $shop = $this->resolveShop();
        if (!$shop) {
            $this->error('No shop found — install the app on a dev store first.');

            return self::FAILURE;
        }

        if (!$this->option('force') && !$this->confirm("Seed DEMO data into {$shop->domain}? (dev stores only)", true)) {
            return self::SUCCESS;
        }

        if ($shop->setting('demo_seeded_at') && !$this->option('fresh')) {
            $this->warn('Already seeded. Re-run with --fresh to rebuild demo data.');

            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $this->cleanup($shop);
        }

        $this->info("Seeding demo board for {$shop->domain} …");

        [$ravi, $sunita, $arjun] = $this->seedMembers($shop);
        $attention = $this->seedColumn($shop);
        $orders = $this->pullRealOrders($shop);
        $this->seedTasks($shop, $attention, $ravi, $sunita, $arjun, $orders);

        $portalUrl = rtrim((string) config('shopify.app_url'), '/').'/staff/invite/'.$sunita->issuePortalToken();
        $shop->setSetting('demo_seeded_at', now()->toIso8601String());
        $shop->save();

        $this->newLine();
        $this->info('Demo board ready.');
        $this->table(['What', 'Count / detail'], [
            ['Members', 'Ravi Kumar (owner, verified), Sunita (staff, verified), Arjun (staff, unverified)'],
            ['Columns', '+ "Needs Attention" between To Do and In Progress'],
            ['Tasks', count($this->seedSpec($orders)).' (2 done, 1 overdue NDR, 1 auto-COD)'],
            ['Real order links', count($orders) ? implode(', ', array_column($orders, 'name')) : 'none found — used made-up numbers'],
            ['Staff portal (Sunita)', $portalUrl],
        ]);
        $this->comment('Tip: open the staff portal link on your phone — that is screenshot #6.');

        return self::SUCCESS;
    }

    protected function resolveShop(): ?Shop
    {
        $domain = $this->argument('shop');

        return $domain ? Shop::where('domain', $domain)->first() : Shop::first();
    }

    protected function cleanup(Shop $shop): void
    {
        // Demo tasks carry one of these actors (some mimic the automations).
        $actors = [
            self::ACTOR,
            \App\Services\CodAutoTask::ACTOR,
            \App\Services\NdrAutoTask::ACTOR,
            \App\Services\RecurringChores::ACTOR,
        ];
        $shop->tasks()->whereIn('created_by_name', $actors)->delete();
        $shop->members()->whereIn('phone', self::DEMO_PHONES)->delete();
        $shop->columns()->where('name', 'Needs Attention')->delete();
        $shop->setSetting('demo_seeded_at', null);
        $shop->save();
    }

    protected function seedMembers(Shop $shop): array
    {
        $mk = fn (string $name, string $phone, string $role, bool $verified) => $shop->members()
            ->updateOrCreate(['phone' => $phone], [
                'name' => $name, 'role' => $role, 'active' => true, 'whatsapp_verified' => $verified,
            ]);

        return [
            $mk('Ravi Kumar', self::DEMO_PHONES[0], Member::ROLE_OWNER, true),
            $mk('Sunita', self::DEMO_PHONES[1], Member::ROLE_STAFF, true),
            $mk('Arjun', self::DEMO_PHONES[2], Member::ROLE_STAFF, false),
        ];
    }

    protected function seedColumn(Shop $shop)
    {
        $existing = $shop->columns()->where('name', 'Needs Attention')->first();
        if ($existing) {
            return $existing;
        }

        $shop->columns()->where('position', '>=', 1)->increment('position');

        return $shop->columns()->create(['name' => 'Needs Attention', 'position' => 1]);
    }

    /** Real orders from the dev store so resource links click somewhere real. */
    protected function pullRealOrders(Shop $shop): array
    {
        try {
            $data = (new ShopifyClient($shop))->graphql(
                '{ orders(first: 4) { nodes { id legacyResourceId name } } }'
            );

            return collect($data['orders']['nodes'] ?? [])
                ->map(fn ($n) => ['id' => (int) $n['legacyResourceId'], 'name' => $n['name']])
                ->all();
        } catch (\Throwable $e) {
            $this->warn('Could not fetch orders ('.$e->getMessage().') — tasks will use made-up order numbers.');

            return [];
        }
    }

    protected function seedSpec(array $orders): array
    {
        $o = fn (int $i) => $orders[$i] ?? null;

        // [template|null, title, col, priority, assignee, done-ticks, due, extra]
        $tpl = fn ($k) => config("task_templates.{$k}");

        $mk = function (string $key, ?int $orderIdx, string $col, ?string $assignee, int $ticksDone, ?int $dueHrs, array $over = []) use ($tpl, $o) {
            $t   = $tpl($key);
            $ord = $orderIdx !== null ? $o($orderIdx) : null;
            $orderName = $ord['name'] ?? ('#'.(5123 + ($orderIdx ?? 0) * 37));
            $desc = collect($t['checklist'] ?? [])->map(fn ($i, $ix) => ($ix < $ticksDone ? '- [x] ' : '- [ ] ').$i)->implode("\n");

            return ['tpl' => $t, 'desc' => $desc, 'col' => $col, 'assignee' => $assignee,
                    'order' => $ord, 'order_name' => $orderName, 'due' => $dueHrs] + $over;
        };

        return [
            $mk('cod-confirm', 0, 'todo', 'Sunita', 2, 8),
            $mk('ndr-followup', 1, 'attention', 'Ravi Kumar', 1, -3, [
                'pre' => "Courier NDR reason: Customer not available (2 attempts)\nAWB: 1433210987654321\n\n",
                'actor' => \App\Services\NdrAutoTask::ACTOR,
            ]),
            $mk('rto-high-risk', 2, 'attention', null, 0, 26),
            $mk('prepaid-convert', 0, 'progress', 'Arjun', 3, 20),
            $mk('address-fix', 3, 'progress', 'Sunita', 2, 6),
            $mk('cod-remittance', null, 'todo', 'Ravi Kumar', 0, 120, ['actor' => \App\Services\RecurringChores::ACTOR]),
            $mk('delayed-shipment', 1, 'progress', 'Arjun', 1, 30),
            $mk('return-pickup', 2, 'done', 'Sunita', 4, -20, ['done' => true]),
            $mk('cod-confirm', 3, 'todo', null, 0, 18, ['actor' => \App\Services\CodAutoTask::ACTOR]),
            ['tpl' => null, 'desc' => "- [x] Bubble wrap\n- [x] Tape\n- [ ] Fragile stickers", 'col' => 'progress',
             'assignee' => 'Arjun', 'order' => null, 'order_name' => null, 'due' => 48, 'ticks' => 0,
             'title' => 'Restock packing material for the week'],
            ['tpl' => null, 'desc' => "- [x] 6 kurtis shot\n- [x] 6 co-ord sets shot\n- [x] Uploaded to Drive", 'col' => 'done',
             'assignee' => 'Sunita', 'order' => null, 'order_name' => null, 'due' => -28, 'ticks' => 0,
             'title' => 'Photograph 12 new SKUs for the festival drop', 'done' => true],
        ];
    }

    protected function seedTasks(Shop $shop, $attention, Member $ravi, Member $sunita, Member $arjun, array $orders): void
    {
        $cols = [
            'todo'      => $shop->columns()->where('is_done_stage', false)->orderBy('position')->first(),
            'attention' => $attention,
            'progress'  => $shop->columns()->where('is_done_stage', false)->orderBy('position')->skip(1)->first()
                ?? $shop->columns()->where('is_done_stage', false)->orderBy('position')->first(),
            'done'      => $shop->columns()->where('is_done_stage', true)->first(),
        ];

        $byName = ['Ravi Kumar' => $ravi, 'Sunita' => $sunita, 'Arjun' => $arjun];
        $created = 0;

        foreach ($this->seedSpec($orders) as $spec) {
            $col = $cols[$spec['col']] ?? $cols['todo'];
            if (!$col) {
                continue;
            }

            $title = $spec['title'] ?? str_replace(
                ['{order}', '{date}'],
                [$spec['order_name'] ?? '#5123', now()->format('d M Y')],
                $spec['tpl']['title'] ?? 'Demo task'
            );

            $done = !empty($spec['done']);

            $shop->tasks()->create([
                'column_id'       => $col->id,
                'title'           => $title,
                'description'     => ($spec['pre'] ?? '').$spec['desc'],
                'priority'        => $spec['tpl']['priority'] ?? 'medium',
                'due_at'          => $spec['due'] !== null ? now()->addHours($spec['due']) : null,
                'assignee_id'     => $spec['assignee'] ? $byName[$spec['assignee']]->id : null,
                'resource_type'   => $spec['order'] ? 'order' : null,
                'resource_id'     => $spec['order']['id'] ?? null,
                'resource_gid'    => $spec['order'] ? "gid://shopify/Order/{$spec['order']['id']}" : null,
                'resource_title'  => $spec['order'] ? $spec['order']['name'] : null,
                'resource_url'    => $spec['order'] ? $shop->adminBaseUrl().'/orders/'.$spec['order']['id'] : null,
                'position'        => ((int) $shop->tasks()->where('column_id', $col->id)->max('position')) + 1,
                'created_by_name' => $spec['actor'] ?? self::ACTOR,
                'completed_at'    => $done ? now()->subHours(abs((int) $spec['due']) ?: 20) : null,
            ]);
            $created++;
        }

        $this->line("  …{$created} demo tasks created");
    }
}
