<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    public const RESOURCE_TYPES = ['order', 'draft_order', 'product', 'customer', 'article'];

    protected $fillable = [
        'shop_id', 'column_id', 'title', 'description', 'priority',
        'due_at', 'assignee_id', 'created_by_name',
        'resource_type', 'resource_id', 'resource_gid', 'resource_title', 'resource_url',
        'position', 'completed_at',
    ];

    protected $casts = [
        'due_at'       => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function column(): BelongsTo
    {
        return $this->belongsTo(BoardColumn::class, 'column_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'assignee_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(TaskActivity::class)->latest('id');
    }

    public function isDone(): bool
    {
        return !is_null($this->completed_at);
    }

    public function isOverdue(): bool
    {
        return !$this->isDone() && $this->due_at && $this->due_at->isPast();
    }

    public function resourceLabel(): string
    {
        $map = [
            'order'       => 'Order',
            'draft_order' => 'Draft order',
            'product'     => 'Product',
            'customer'    => 'Customer',
            'article'     => 'Blog post',
        ];

        return $map[$this->resource_type] ?? 'Link';
    }
}
