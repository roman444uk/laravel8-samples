<?php

namespace App\Models\Import;

use Illuminate\Database\Eloquent\Model;

class ImportProduct extends Model
{

    protected $fillable = [
        'import_task_id',
        'uid',
        'barcode',
        'grupped_by',
        'data',
        'additionalInfo',
        'status',
    ];

    protected $casts = [
        'data'           => 'array',
        'additionalInfo' => 'array',
    ];
}
