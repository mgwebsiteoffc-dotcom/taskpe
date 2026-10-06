<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $table->string('kind', 30);                          // otp|task_assigned|task_reminder|digest|test
            $table->string('phone', 20);
            $table->string('channel', 10)->default('template');  // template|text
            $table->json('payload')->nullable();
            $table->string('status', 15)->default('queued');     // queued|sent|delivered|failed
            $table->unsignedBigInteger('whatify_message_id')->nullable();
            $table->string('wamid')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shop_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_logs');
    }
};
