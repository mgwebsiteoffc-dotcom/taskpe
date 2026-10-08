<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The app's section menu lives in Shopify's own admin sidebar (App Bridge reads
 * a <ui-nav-menu> the SPA mounts), and each entry is a path Laravel has to
 * answer: /team, /settings, /plan. Same shell every time — only the section the
 * SPA opens on load differs — because a tab strip inside the app would be a
 * second nav fighting the admin's.
 *
 * These are the two things that can silently break that: a real route losing the
 * {section} pattern, or the constrained pattern swallowing something it must not
 * (/privacy, /staff, /api/*).
 */
class AppSectionsTest extends TestCase
{
    public function test_each_section_path_serves_the_shell_with_its_section(): void
    {
        foreach (['board', 'team', 'settings', 'plan'] as $section) {
            $this->get('/'.$section)
                ->assertOk()
                ->assertSee("view: \"".$section. "\"", false);
        }
    }

    public function test_the_root_is_the_board(): void
    {
        $this->get('/')->assertOk()->assertSee('view: "board"', false);
    }

    public function test_a_query_view_still_wins_for_deep_links(): void
    {
        // The extensions and the staff links deep-link with ?task=…, and a
        // ?view= link is what a merchant emails a colleague.
        $this->get('/?view=settings')->assertOk()->assertSee('view: "settings"', false);
    }

    public function test_the_section_pattern_cannot_swallow_other_routes(): void
    {
        $this->get('/privacy')->assertOk()->assertDontSee('view: "', false);
        $this->getJson('/api/board')->assertStatus(401);      // still the API, still token-authed
        $this->get('/nonsense')->assertNotFound();
        $this->get('/board/extra')->assertNotFound();   // one segment only
        $this->get('/plan/edit')->assertNotFound();
    }

    public function test_a_bare_shop_domain_still_forces_oauth_at_top_level(): void
    {
        // Kept deliberately: this is the pre-existing "opened outside the admin"
        // behaviour, and routing /{section} through the same controller must not
        // have quietly dropped the redirect.
        $this->get('/team?shop=demo-store.myshopify.com')
            ->assertRedirect('/auth/shopify?shop=demo-store.myshopify.com');
    }
}
