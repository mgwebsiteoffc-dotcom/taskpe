<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Staff-portal login token (hashed). Lets teammates with NO
            // Shopify admin access (Basic plan = 1 staff seat) use the full
            // board on the web at /staff via a personal invite link.
            $table->string('portal_token_hash', 64)->nullable()->index()->after('otp_expires_at');
            $table->timestamp('portal_issued_at')->nullable()->after('portal_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn(['portal_token_hash', 'portal_issued_at']);
        });
    }
};
