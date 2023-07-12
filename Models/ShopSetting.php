<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopSetting extends Model
{
    protected $fillable = [
        'user_id',
        'marketplace',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];
}
