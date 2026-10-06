<?php

namespace Tests\Unit;

use App\Services\TaskTemplates;
use Tests\TestCase;

/**
 * No database here: these are the formatting rules every creation path shares
 * (board picker, Admin bulk action, order-page block, COD webhook). They are
 * worth pinning because the bulk action's "already filed?" test compares the
 * *rendered title* — so the JS and PHP renderings must agree exactly, and the
 * checklist lines must stay parseable by public/js/app.js.
 */
class TaskTemplatesTest extends TestCase
{
    public function test_the_configured_templates_all_render_a_title_and_a_checklist(): void
    {
        $templates = TaskTemplates::all();
        $this->assertNotEmpty($templates, 'config/task_templates.php did not load');

        foreach ($templates as $key => $tpl) {
            $title = TaskTemplates::renderTitle($tpl, '#1042', 1042, 'order');

            $this->assertNotSame('', $title, "{$key} renders an empty title");
            $this->assertDoesNotMatchRegularExpression('/\s{2,}/', $title, "{$key} keeps double spaces");
            $this->assertLessThanOrEqual(190, strlen($title), "{$key} title overflows the DB column");
            $this->assertSame(
                substr_count($title, '{'),
                0,
                "{$key} has an unreplaced placeholder"
            );

            if (!empty($tpl['checklist'])) {
                $ck = TaskTemplates::parseChecklist(TaskTemplates::checklist($tpl));
                $this->assertCount(count($tpl['checklist']), $ck['items'], "{$key} checklist round-trip broke");
                $this->assertFalse($ck['items'][0]['done'], "{$key} must start unticked");
                $this->assertSame($ck['items'][0]['text'], $tpl['checklist'][0]);
            }
        }
    }

    public function test_the_cod_template_title_matches_what_the_board_javascript_builds(): void
    {
        // app.js: 'Confirm COD order {order}' + "06 Oct 2026" style dates.
        $tpl = TaskTemplates::find('cod-confirm');
        $this->assertNotNull($tpl);
        $this->assertSame('Confirm COD order #1042', TaskTemplates::renderTitle($tpl, '#1042', 1042, 'order'));

        $dated = TaskTemplates::renderTitle(TaskTemplates::all()['cod-remittance'], null, null, null);
        $this->assertMatchesRegularExpression(
            '/^COD remittance check — week of \d{2} [A-Z][a-z]{2} \d{4}$/',
            $dated,
            'the {date} format must stay "d M Y" so it matches the SPA (duplicate detection depends on it)'
        );
    }

    public function test_a_bulk_selection_without_titles_still_names_its_task(): void
    {
        // Admin hands us GIDs only; "order 5123" is honest, "#5123" would lie.
        $title = TaskTemplates::renderTitle(TaskTemplates::find('cod-confirm'), null, 5123, 'order');

        $this->assertSame('Confirm COD order order 5123', $title);
    }

    public function test_resource_links_are_built_for_every_type(): void
    {
        $this->assertSame('gid://shopify/Order/7', TaskTemplates::gidFor('order', 7));
        $this->assertSame('gid://shopify/DraftOrder/7', TaskTemplates::gidFor('draft_order', 7));
        $this->assertSame('gid://shopify/Product/7', TaskTemplates::gidFor('product', 7));
        $this->assertSame('gid://shopify/Customer/7', TaskTemplates::gidFor('customer', 7));
        $this->assertSame('gid://shopify/Article/7', TaskTemplates::gidFor('article', 7));

        $shop = new \App\Models\Shop(['handle' => 'my-store']);
        $this->assertSame(
            'https://admin.shopify.com/store/my-store/draft_orders/7',
            TaskTemplates::adminUrl($shop, 'draft_order', 7)
        );
    }

    public function test_only_order_templates_are_offered_on_the_orders_page(): void
    {
        $this->assertArrayHasKey('cod-confirm', TaskTemplates::all());
        $this->assertArrayHasKey('cod-remittance', TaskTemplates::all());

        $forOrders = TaskTemplates::forResource('order');
        $this->assertArrayHasKey('cod-confirm', $forOrders);
        $this->assertArrayHasKey('ndr-followup', $forOrders);
        $this->assertArrayNotHasKey(
            'cod-remittance',
            $forOrders,
            'a once-a-week chore must never be offered next to a 30-order bulk selection'
        );

        // No product template exists yet, so a product page offers nothing
        // rather than filing order chores against SKUs.
        $this->assertSame([], TaskTemplates::forResource('product'));
    }
}
