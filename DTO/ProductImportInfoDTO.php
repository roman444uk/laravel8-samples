<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class ProductImportInfoDTO extends DataTransferObject
{
    public bool $has_error;
    public string $message;
    public string $created_at;
}
