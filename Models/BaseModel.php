<?php

namespace App\Models;

use App\Core\Traits\SpatieLogsActivity;
use App\Traits\HashTrait;
use Illuminate\Database\Eloquent\Model;

class BaseModel extends Model
{
    use HashTrait, SpatieLogsActivity;
}
