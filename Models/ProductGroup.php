<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ProductGroup extends Model
{
    public Collection $items;

    protected $fillable = [
        'sku',
        'user_id',
        'products',
        'main_product',
    ];

    protected $casts = [
        'products' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
