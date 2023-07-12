<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class ProductVariationDTO extends DataTransferObject
{
    public string $id;
    public ?int $variation_id;
    public string $vendor_code;
    public ?array $images = [];
    public ?array $files = [];
    public ?array $items = [];
    public ?string $published;
    public ?string $status;
    public ?array $attributes = [];
    public ?string $barcode;
    public ?array $sku;
    public ?array $settings = [];
    public ?array $prices = [];
    public ?array $stocks = [];
}
