<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Idempotency ledger — Shopify may deliver the same webhook twice.
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->unique();              // X-Shopify-Webhook-Id
            $table->string('shop_domain');
            $table->string('topic');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
