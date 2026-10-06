<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();               // mystore.myshopify.com
            $table->string('handle');                          // mystore (for admin deep links)
            $table->string('name')->nullable();
            $table->text('access_token')->nullable();          // encrypted cast
            $table->string('scopes')->nullable();
            $table->string('plan')->default('free');
            $table->string('charge_id')->nullable();
            $table->string('timezone')->default('Asia/Kolkata');
            $table->text('whatify_api_key')->nullable();       // encrypted cast
            $table->unsignedBigInteger('whatify_account_id')->nullable();
            $table->json('settings')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamp('redacted_at')->nullable();      // GDPR shop/redact honoured
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
