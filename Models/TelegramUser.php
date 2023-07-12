<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TelegramUser extends Model
{
    protected $fillable
        = [
            'user_id',
            'telegram_id',
            'status',
            'username',
            'active',
            'messages',
        ];

    protected $casts = [
        'messages' => 'array'
    ];

    public function scopeActive(Builder $query)
    {
        return $query->where('status', 1)->where('active', 1);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}