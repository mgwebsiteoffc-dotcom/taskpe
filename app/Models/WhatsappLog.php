<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsappLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'shop_id', 'member_id', 'task_id', 'kind', 'phone',
        'channel', 'payload', 'status', 'whatify_message_id', 'wamid', 'error',
    ];

    protected $casts = ['payload' => 'array'];

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
