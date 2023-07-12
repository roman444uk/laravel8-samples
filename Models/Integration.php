<?php

namespace App\Models;

use App\Traits\HashTrait;
use Illuminate\Database\Eloquent\Model;

class Integration extends Model
{
    use HashTrait;

    public int $system_status = 0;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'price_list_id',
        'tax_id',
        'settings',
        'status',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'published');
    }
    
    public function scopeMy($query){
        return $query->where('user_id', auth()->id());
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function price_list()
    {
        return $this->belongsTo(PriceList::class)->with('products');
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    public function tasks()
    {
        return $this->hasMany(ExportTask::class);
    }
}
