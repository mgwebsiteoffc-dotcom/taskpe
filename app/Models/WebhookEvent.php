<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebhookEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['webhook_id', 'shop_domain', 'topic', 'processed_at'];

    protected $casts = ['processed_at' => 'datetime'];
}
