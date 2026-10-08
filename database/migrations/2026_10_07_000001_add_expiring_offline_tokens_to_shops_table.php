<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shopify moved public apps to EXPIRING offline access tokens: the Admin API refuses the
 * non-expiring kind ("Non-expiring access tokens are no longer accepted for the Admin
 * API"), and the replacement arrives as a pair — an access token that lives about an hour
 * and a refresh token that lives about 90 days, each with its own expiry.
 *
 * That is two more secrets and two more dates per store than the old single-column model
 * could hold, and the dates have to be absolute: a relative `expires_in` written at install
 * is meaningless the first time a queued job reads the row.
 *
 * All four columns are nullable, because a row legitimately has none of them:
 *   - an install from before this change  → access token, nothing else (TokenVault converts it)
 *   - an install that never finished OAuth → nothing at all
 * Encrypted cast on `refresh_token`: it is as powerful as the access token it renews, so
 * it is never a plain column and never reaches the browser or a log line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            if (!Schema::hasColumn('shops', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('access_token');
            }

            if (!Schema::hasColumn('shops', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('scopes');
            }

            if (!Schema::hasColumn('shops', 'refresh_expires_at')) {
                $table->timestamp('refresh_expires_at')->nullable()->after('token_expires_at');
            }

            if (!Schema::hasColumn('shops', 'token_rotated_at')) {
                // When this app last renewed the pair — the number `taskpe:tokens` prints,
                // and the one that tells a rotated token apart from a reinstall.
                $table->timestamp('token_rotated_at')->nullable()->after('refresh_expires_at');
            }
        });
    }

    public function down(): void
    {
        // Filtered the same way up() is, so a rollback on a partially migrated host
        // drops what exists instead of failing on what never got added.
        $drop = array_values(array_filter(
            ['refresh_token', 'token_expires_at', 'refresh_expires_at', 'token_rotated_at'],
            fn ($column) => Schema::hasColumn('shops', $column)
        ));

        if ($drop !== []) {
            Schema::table('shops', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }
};
