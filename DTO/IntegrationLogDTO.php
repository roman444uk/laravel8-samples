<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class IntegrationLogDTO extends DataTransferObject
{
    public bool $has_error;
    public string $message;
    public string $created_at;
}
