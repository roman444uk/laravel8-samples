<?php

namespace App\Enums;

enum DictionaryTypes: string
{

    case DICTIONARY = 'dictionary';
    case DICTIONARY_VALUE = 'dictionary_value';
    case ATTRIBUTE = 'attribute';
    case ATTRIBUTE_VALUE = 'attribute_value';
    case CATEGORY = 'category';
}
