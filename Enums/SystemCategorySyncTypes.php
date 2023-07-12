<?php

namespace App\Enums;

enum SystemCategorySyncTypes: string
{
    case ALL = 'all';
    case SYNC = 'sync';
    case NOT_SYNC = 'not_sync';
}
