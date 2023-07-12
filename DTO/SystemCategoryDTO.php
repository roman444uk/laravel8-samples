<?php

namespace App\DTO;

use Spatie\DataTransferObject\DataTransferObject;

class SystemCategoryDTO extends DataTransferObject
{
    public int $id;
    public string $title;
    public ?string $parent_id;
    public bool $has_children;
}