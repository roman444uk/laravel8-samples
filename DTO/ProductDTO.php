<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class ProductDTO extends DataTransferObject
{
    public string $id;
    public string $external_id;
    public string $sku;
    public string $title;
    public string $description;
    public string $barcode;
    public ?int $category_id;
    public string $primary_image;
    public array $images;
    public float $weight;
    public float $width;
    public float $height;
    public float $length;
    public string $status;
    public ?array $prices = [];
    public ?array $stocks = [];
    public string $country;
    public array $attributes;
    public array $variations;
}
