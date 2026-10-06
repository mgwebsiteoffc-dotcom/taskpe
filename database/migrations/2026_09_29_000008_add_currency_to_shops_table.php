<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Merchant's shop/billing currency (ISO 4217) for localized plan
            // pricing. NULL until first fetched (install or billing flow).
            $table->string('currency', 3)->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
