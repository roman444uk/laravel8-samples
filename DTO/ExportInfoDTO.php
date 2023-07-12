<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class ExportInfoDTO extends DataTransferObject
{
    public bool $has_error;
    public string $message;
    public string $created_at;
    public ?array $log;
    public ?array $result;
    public string $marketplace;
    public array $details;
}
