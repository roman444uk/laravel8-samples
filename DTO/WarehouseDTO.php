<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class WarehouseDTO extends DataTransferObject
{
    public string $name;
    public int $id;
}