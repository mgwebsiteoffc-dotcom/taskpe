<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\MintsSessionToken;
use Tests\TestCase;

/**
 * The board-load failure every merchant report starts with ("Something went
 * wrong loading the board") is always one of three things at this boundary:
 * no token arrived, the token could not be verified, or the shop is not
 * installed. Each has its own machine-readable error code so the SPA can say
 * which one it is — and a shared-hosting box that eats the Authorization
 * header must still authenticate via the X-TaskPe-Auth twin.
 */
class BoardAuthTest extends TestCase
{
    use RefreshDatabase;
    use MintsSessionToken;

    public function test_board_without_any_token_is_a_tellable_missing_token(): void
    {
        $this->getJson('/api/board')
            ->assertStatus(401)
            ->assertJsonPath('error', 'missing_session_token')
            ->assertJsonStructure(['error', 'message']);
    }

    public function test_valid_session_token_for_an_unknown_shop_reports_not_installed(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken())
            ->getJson('/api/board')
            ->assertStatus(401)
            ->assertJsonPath('error', 'not_installed')
            ->assertJsonPath('shop', 'demo-store.myshopify.com');
    }

    public function test_token_survives_a_host_that_strips_the_authorization_header(): void
    {
        // LiteSpeed/CGI setups drop `Authorization` before PHP sees it. The
        // SPA sends the same JWT in X-TaskPe-Auth; if only that one arrives,
        // auth must still get as far as the shop lookup (i.e. NOT be reported
        // as a missing token, which is what used to dead-end the whole board).
        $this->withHeader('X-TaskPe-Auth', $this->sessionToken())
            ->getJson('/api/board')
            ->assertStatus(401)
            ->assertJsonPath('error', 'not_installed');
    }

    public function test_a_foreign_signed_token_is_rejected_with_a_reason(): void
    {
        $parts = explode('.', $this->sessionToken());
        $parts[2] = 'not-the-signature';

        $this->withHeader('Authorization', 'Bearer '.implode('.', $parts))
            ->getJson('/api/board')
            ->assertStatus(401)
            ->assertJsonPath('error', 'invalid_session_token')
            ->assertJsonPath('reason', 'Bad signature');
    }

}
