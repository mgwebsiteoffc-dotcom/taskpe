<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('columns', 'team')) {
            return;     // shared hosting runs `migrate --force` more than once
        }

        Schema::table('columns', function (Blueprint $table) {
            // Which part of the shop owns this column — "Accounting", "Warehouse".
            // Free text on purpose (every shop names its teams differently); the
            // dashboard groups by it and the board filters by it.
            $table->string('team', 40)->nullable()->after('is_done_stage')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('columns', 'team')) {
            return;
        }

        Schema::table('columns', function (Blueprint $table) {
            $table->dropColumn('team');
        });
    }
};
