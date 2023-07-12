<?php

namespace App\Models;

use App\DTO\ProductVariationDTO;
use App\DTO\ProductVariationItemDTO;
use App\Observers\ProductVariationObserver;
use App\Services\Shop\AttributeService;
use App\Traits\ProductHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\DataTransferObject\Exceptions\UnknownProperties;

class ProductVariation extends Model
{
    use ProductHelper;

    public const PRICE_TYPE = 'product_variation';

    protected $fillable = [
        'product_id',
        'vendor_code',
        'images',
        'files',
        'status',
        'data',
        'uuid',
        'attributes',
        'barcode',
        'sku',
        'settings',
        'errors',
    ];

    protected $casts = [
        'images'     => 'array',
        'files'      => 'array',
        'data'       => 'array',
        'attributes' => 'array',
        'sku'        => 'array',
        'settings'   => 'array',
        'errors'     => 'array',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function items()
    {
        return $this->hasMany(ProductVariationItem::class);
    }

    public function marketplace_products()
    {
        return $this->hasMany(MarketplaceProduct::class, 'object_id', 'id')->where([
            'type' => self::PRICE_TYPE,
        ]);
    }

    public function getImageObjectsAttribute(): array
    {
        $images = [];

        foreach ($this->images ?? [] as $image) {
            if (filter_var($image, FILTER_VALIDATE_URL)) {
                $images[] = [
                    'path' => $image,
                    'url'  => $image,
                    'size' => getRemoteFileSize($image)
                ];

                continue;
            }

            try {
                $url = Storage::url($image);

                if ($image && $url) {
                    $images[] = [
                        'path' => $image,
                        'url'  => $url,
                        'size' => Storage::size($image)
                    ];
                }
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            }
        }

        return $images;
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

    public function getImageUrlsAttribute(): array
    {
        $images = [];

        foreach ($this->images ?? [] as $image) {
            if (filter_var($image, FILTER_VALIDATE_URL)) {
                $images[] = $image;

                continue;
            }

            try {
                $url = Storage::url($image);

                if ($image && $url) {
                    $images[] = $url;
                }
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            }
        }

        return $images;
    }

    /**
     * @param bool $forApi
     *
     * @return ProductVariationDTO
     * @throws UnknownProperties
     */
    public function getProductVariationDTO(bool $forApi = false): ProductVariationDTO
    {
        $attributes = [];
        if ($forApi) {
            $attributeService = new AttributeService();
            foreach ($this->getAttribute('attributes') ?? [] as $attributeId => $attributeValue) {
                if (is_array($attributeValue)) {
                    continue;
                }

                $attributes[] = $attributeService->getSystemAttributeNameAndValue($attributeId, $attributeValue);
            }
        } else {
            $attributes = $this->getAttribute('attributes');
        }

        /** Приведем к bool типу для vue компонента */
        $sku_for_all = true;
        $settings    = $this->settings ?? [];
        if (isset($settings['sku_for_all'])) {
            $sku_for_all = (bool)$settings['sku_for_all'];
        }
        $settings['sku_for_all'] = $sku_for_all;

        $prices = $this->getPricesForApi($this->prices);
        $stocks = $this->getStocksForApi($this->stocks);

        return new ProductVariationDTO([
            'id'           => $this->uuid,
            'variation_id' => $this->id,
            'vendor_code'  => $this->vendor_code,
            'images'       => $forApi ? $this->image_urls : $this->image_objects,
            'files'        => $forApi ? $this->file_urls : $this->file_objects,
            'items'        => $this->getItemsDTOAttribute($forApi),
            'published'    => $this->status === 'published',
            'status'       => $this->status,
            'attributes'   => $attributes,
            'barcode'      => $this->barcode,
            'sku'          => $this->sku ?? [],
            'settings'     => $settings,
            'prices'       => $prices,
            'stocks'       => $stocks
        ]);
    }

    /**
     * @param bool $forApi
     *
     * @return array
     */
    public function getItemsDTOAttribute(bool $forApi = false): array
    {
        return $this->items->map(function (ProductVariationItem $item) use ($forApi) {
            $attributeService = new AttributeService();

            $prices = $this->getPricesForApi($item->prices);
            $stocks = $this->getStocksForApi($item->stocks);

            $attributes = [];
            if ($forApi) {
                foreach ($item->getAttribute('attributes') ?? [] as $attributeId => $attributeValue) {
                    if (is_array($attributeValue)) {
                        continue;
                    }

                    $attributes[] = $attributeService->getSystemAttributeNameAndValue($attributeId, $attributeValue);
                }
            } else {
                $attributes = $item->getAttribute('attributes');
            }

            return new ProductVariationItemDTO([
                'id'         => $item->uuid,
                'value'      => $item->value,
                'barcode'    => $item->barcode,
                'settings'   => $item->settings,
                'attributes' => $attributes,
                'prices'     => $prices,
                'stocks'     => $stocks
            ]);
        })->toArray();
    }

    /**
     * @param $query
     *
     * @return mixed
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * @param Builder $query
     * @param int $user_id
     *
     * @return Builder
     */
    public function scopeByUserId(Builder $query, int $user_id): Builder
    {
        return $query->whereRaw('product_id IN (SELECT DISTINCT id FROM products WHERE user_id = ?)', [$user_id]);
    }

    public static function boot()
    {
        parent::boot();

        ProductVariation::observe(ProductVariationObserver::class);
    }

    public function getFileUrlsAttribute(): array
    {
        $files = [];

        foreach ($this->files ?? [] as $file) {
            if (filter_var($file['path'], FILTER_VALIDATE_URL)) {
                $files[] = [
                    'path' => $file['path'],
                    'type' => $file['type'],
                ];

                continue;
            }

            try {
                $url = Storage::url($file['path']);

                if ( ! empty($file['path']) && ! empty($url)) {
                    $files[] = [
                        'path' => $url,
                        'type' => $file['type'],
                    ];
                }
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            }
        }

        return $files;
    }

    public function getFileObjectsAttribute(): array
    {
        $files = [];
        foreach ($this->files ?? [] as $file) {
            if (filter_var($file['path'], FILTER_VALIDATE_URL)) {
                $files[] = [
                    'path' => $file['path'],
                    'url'  => $file['path'],
                    'size' => getRemoteFileSize($file['path']),
                    'type' => getRemoteFileMime($file['path']),
                    'name' => \File::basename($file['path']),
                ];

                continue;
            }

            try {
                $url = Storage::url($file['path']);

                if ( ! empty($file['path']) && ! empty($url)) {
                    $files[] = [
                        'path' => $file['path'],
                        'url'  => $url,
                        'size' => Storage::size($file['path']),
                        'type' => Storage::mimeType($file['path']),
                        'name' => \File::basename($file['path']),
                    ];
                }
            } catch (\Exception $e) {
                logger()->info($e->getMessage());
            }
        }

        return $files;
    }
}
