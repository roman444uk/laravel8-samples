<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductStock extends Model
{

    protected $fillable = [
        'price_list_id',
        'type',
        'object_id',
        'marketplace',
        'warehouse_id',
        'fbs_stock',
        'fbo_stock',
        'settings',
        'for_all',
    ];

    protected $casts = [
        'settings' => 'array',
        'for_all'  => 'array',
    ];

    public function user()
    {
        return $this->price_list->user;
    }

    public function price_list()
    {
        return $this->belongsTo(PriceList::class);
    }
}
