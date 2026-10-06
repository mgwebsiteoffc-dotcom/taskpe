<?php

namespace App\Jobs;

use App\Models\Member;
use App\Models\Shop;
use App\Models\Task;
use App\Services\TaskNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one WhatsApp notification through the merchant's Whatify account.
 * Queued (database driver, cron worker on shared hosting) so the board UI
 * never waits on Meta delivery confirmation.
 */
class SendWhatsAppJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /**
     * @param string $kind  otp|task_assigned|task_reminder|digest|test
     * @param array  $extra kind-specific extras (otp code, digest lines...)
     */
    public function __construct(
        public int $shopId,
        public int $memberId,
        public string $kind,
        public ?int $taskId = null,
        public array $extra = [],
    ) {}

    public function handle(): void
    {
        $shop   = Shop::find($this->shopId);
        $member = Member::find($this->memberId);
        if (!$shop || !$member || !$member->active) {
            return;
        }

        $notifier = new TaskNotifier($shop);

        match ($this->kind) {
            'otp'           => $notifier->sendOtp($member, (string) ($this->extra['code'] ?? '')),
            'task_assigned' => $this->withTask(fn (Task $t) => $notifier->notifyAssigned($t, $member)),
            'task_reminder' => $this->withTask(fn (Task $t) => $notifier->notifyReminder($t, $member)),
            'digest'        => $notifier->sendDigest($member, (string) ($this->extra['label'] ?? ''), (string) ($this->extra['summary'] ?? '')),
            'test'          => $notifier->sendTest($member),
            default         => null,
        };
    }

    protected function withTask(callable $fn): void
    {
        $task = $this->taskId ? Task::find($this->taskId) : null;
        if ($task) {
            $fn($task);
        }
    }
}
