<?php

namespace App\Enums;

enum ExportTaskStatuses: int
{
    case PENDING = 1;
    case IMPORTED = 2;
    case FAILED = 3;
}
