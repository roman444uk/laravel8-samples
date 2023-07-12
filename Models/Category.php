<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;

class Category extends Model
{
    use HasRecursiveRelationships;

    protected $fillable
        = [
            'user_id',
            'title',
            'status',
            'description',
            'image',
            'meta_title',
            'meta_description',
            'meta_keywords',
            'parent_id',
            'sort',
            'settings',
            'external_id',
            'system_category_id',
        ];

    protected $casts = [
        'settings' => 'array'
    ];

    public function getImageUrlAttribute(?bool $hideBlank = true)
    {
        if ($this->image && filter_var(Storage::url($this->image), FILTER_VALIDATE_URL)) {
            return Storage::url($this->image);
        }

        return ! $hideBlank ? asset(theme()->getMediaUrlPath().'svg/files/blank-image.svg') : '';
    }

    public function getParentNames()
    {
        if ($this->parent) {
            return $this->parent->getParentNames()." > ".$this->title;
        } else {
            return $this->title;
        }
    }

    public static function boot()
    {
        parent::boot();

        static::deleting(function (Category $category) {
            if ($category->image && Storage::delete($category->image)) {
                $category->image = null;
                $category->save();
            }

            $category->sync?->delete();

            MarketplaceProductCategory::where(['user_id'     => $category->user_id, 'category_id' => $category->id
            ])->delete();
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeMy($query)
    {
        return $query->where('user_id', auth()->user()->id);
    }

    public function scopeHasSystemCategory($query)
    {
        return $query->whereNotNull('system_category_id');
    }

    public function sync()
    {
        return $this->morphOne(Sync::class, 'syncable');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class);
    }

    public static function getUserCategoryByExternalId(int $user_id, string $external_id): ?Category
    {
        return Category::where([
            'user_id'     => $user_id,
            'external_id' => $external_id,
        ])->first();
    }

    /** Системная категория */
    public function system_category()
    {
        return $this->belongsTo(System\Category::class, 'system_category_id');
    }
}
