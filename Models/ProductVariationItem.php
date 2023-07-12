<?php

namespace App\Models;

use App\Observers\ProductVariationItemObserver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ProductVariationItem extends Model
{
    public const PRICE_TYPE = 'product_variation_item';

    protected $fillable = [
        'uuid',
        'product_variation_id',
        'value',
        'barcode',
        'settings',
        'attributes',
    ];

    protected $casts = [
        'settings'   => 'array',
        'attributes' => 'array',
    ];

    public function product_variation()
    {
        return $this->belongsTo(ProductVariation::class, 'product_variation_id');
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class, 'object_id')
            ->where(['type' => self::PRICE_TYPE]);
    }

    public function stocks()
    {
        return $this->hasMany(ProductStock::class, 'object_id')
            ->where(['type' => self::PRICE_TYPE]);
    }

    /**
     * @param Builder $query
     * @param int $user_id
     *
     * @return Builder
     */
    public function scopeByUserId(Builder $query, int $user_id): Builder
    {
        return $query->whereRaw(
            'product_variation_id IN 
                    (SELECT DISTINCT id FROM product_variations WHERE product_id IN 
                        (SELECT DISTINCT id FROM products WHERE user_id = ?)
                    )',
            [$user_id]
        );
    }

    public static function boot()
    {
        parent::boot();

        ProductVariationItem::observe(ProductVariationItemObserver::class);
    }
}
