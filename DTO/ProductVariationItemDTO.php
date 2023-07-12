<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class ProductVariationItemDTO extends DataTransferObject
{
    public string $id;
    public string $value;
    public ?array $attributes = [];
    public ?string $barcode;
    public ?array $settings = [];
    public ?array $prices = [];
    public ?array $stocks = [];
}
