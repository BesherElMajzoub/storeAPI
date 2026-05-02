<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Image\Enums\Fit;

class Category extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    protected $fillable = [
        'name', 'slug', 'parent_id', 'image', 'is_active', 'sort_order',
        'meta_title', 'meta_description'
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'parent_id'  => 'integer',
        'sort_order' => 'integer',
    ];

    // ──────────────────────────────────────────
    //  Spatie Media Library
    // ──────────────────────────────────────────

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('category_image')
            ->singleFile();
    }

    /**
     * nonQueued() so conversions are generated immediately on upload.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('category_thumb')
            ->fit(Fit::Crop, 200, 200)
            ->format('webp')
            ->nonQueued();

        $this->addMediaConversion('category_card')
            ->fit(Fit::Crop, 400, 250)
            ->format('webp')
            ->nonQueued();

        $this->addMediaConversion('category_banner')
            ->fit(Fit::Crop, 1200, 600)
            ->format('webp')
            ->nonQueued();
    }

    // ──────────────────────────────────────────
    //  Relations
    // ──────────────────────────────────────────

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
