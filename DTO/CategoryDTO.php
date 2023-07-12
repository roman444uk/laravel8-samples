<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class CategoryDTO extends DataTransferObject
{
    public string $id;
    public string $title;
    public ?string $parent_id;
    public string $image;
    public string $status;
}