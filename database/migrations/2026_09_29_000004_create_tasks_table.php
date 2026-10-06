<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('column_id')->constrained('columns')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 10)->default('medium');  // low|medium|high|urgent
            $table->timestamp('due_at')->nullable();
            $table->foreignId('assignee_id')->nullable()->constrained('members')->nullOnDelete();
            $table->string('created_by_name')->nullable();

            // Shopify resource link — only a numeric id, GID, display title
            // and admin URL. No customer PII is ever stored.
            $table->string('resource_type', 20)->nullable();    // order|draft_order|product|customer|article
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('resource_gid')->nullable();
            $table->string('resource_title')->nullable();
            $table->string('resource_url', 512)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'column_id', 'position']);
            $table->index(['shop_id', 'assignee_id']);
            $table->index(['shop_id', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
