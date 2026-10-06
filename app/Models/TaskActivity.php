<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskActivity extends Model
{
    public $timestamps = false;

    protected $fillable = ['shop_id', 'task_id', 'member_id', 'actor_name', 'action', 'meta', 'created_at'];

    protected $casts = [
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public static function record(Task $task, string $action, array $meta = [], ?string $actorName = null): self
    {
        return static::create([
            'shop_id'    => $task->shop_id,
            'task_id'    => $task->id,
            'actor_name' => $actorName ?? 'Store',
            'action'     => $action,
            'meta'       => $meta ?: null,
            'created_at' => now(),
        ]);
    }
}
