<?php

namespace App\Enums;

enum SystemAttributeTypes: string
{
    case STRING = 'string';
    case DICTIONARY = 'dictionary';
    case NUMERIC = 'numeric';
    case BOOLEAN = 'boolean';
}
