<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardColumn extends Model
{
    protected $table = 'columns';

    protected $fillable = ['shop_id', 'name', 'position', 'is_done_stage'];

    protected $casts = ['is_done_stage' => 'boolean'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'column_id')->orderBy('position');
    }
}
