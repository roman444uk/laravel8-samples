<?php

namespace App\Models;

use App\Observers\ProductObserver;
use App\Services\Shop\AttributeService;
use App\Traits\ImageHelper;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory;
    use ImageHelper;

    public const PRICE_TYPE = 'product';

    public array $importData;

    protected $fillable
        = [
            'user_id',
            'title',
            'status',
            'description',
            'image',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'sort',
            'weight',
            'length',
            'width',
            'height',
            'brand_id',
            'sku',
            'barcode',
            'settings',
            'external_id',
            'country_id',
            'category_id',
            'compositions',
            'titles',
            'errors',
        ];

    protected $casts = [
        'settings'     => 'array',
        'compositions' => 'array',
        'titles'       => 'array',
        'errors'       => 'array',
    ];

    public function getImageUrlAttribute(?bool $hideBlank = true)
    {
        if (filter_var($this->image, FILTER_VALIDATE_URL)) {
            return $this->image;
        }

        if ($this->image && filter_var(Storage::url($this->image), FILTER_VALIDATE_URL)) {
            return Storage::url($this->image);
        }

        return ! $hideBlank ? asset(theme()->getMediaUrlPath().'svg/files/blank-image.svg') : '';
    }

    public function getImageObjectAttribute(): array
    {
        $image = [];

        if (filter_var($this->image, FILTER_VALIDATE_URL)) {
            return [
                'path' => $this->image,
                'url'  => $this->image,
                'size' => getRemoteFileSize($this->image)
            ];
        }

        try {
            $url = Storage::url($this->image);

            if ($this->image && $url) {
                return [
                    'path' => $this->image,
                    'url'  => $url,
                    'size' => Storage::size($this->image)
                ];
            }
        } catch (\Exception $e) {
            logger()->info($e->getMessage());
        }

        return $image;
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'product_id');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function attribute_values()
    {
        return $this->belongsToMany(Attribute::class)->withPivot(['value', 'system_value_id']);
    }

    public function variations()
    {
        return $this->hasMany(ProductVariation::class, 'product_id')->orderBy('data->isMain');
    }

    public function variations_active()
    {
        return $this->hasMany(ProductVariation::class, 'product_id')->active()->orderBy('data->isMain');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
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

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function saveProductAttributesOffline(?array $attributesData, User $user)
    {
        $attributeService = new AttributeService();
        $attributeService->saveProductAttributes($this, $attributesData);
    }

    public static function boot()
    {
        parent::boot();

        Product::observe(ProductObserver::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeMy($query)
    {
        return $query->where('user_id', auth()->id());
    }

    public static function getUserProductByExternalId(int $user_id, string $external_id): ?Product
    {
        return Product::where([
            'user_id'     => $user_id,
            'external_id' => $external_id,
        ])->first();
    }

    public static function getUserProductById(int $user_id, string $id): ?Product
    {
        return Product::where([
            'user_id' => $user_id
        ])->where(
            fn($query) => $query->where('external_id', $id)
                ->orWhere('id', (int)$id))
            ->first();
    }
}
