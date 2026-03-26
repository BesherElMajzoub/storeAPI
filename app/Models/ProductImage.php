<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'path',
        'mime_type',
        'original_name',
        'sort_order',
    ];

    // Expose a public URL so API consumers can access the image directly
    public function getUrlAttribute(): ?string
    {
        return $this->path ? Storage::disk('public')->url($this->path) : null;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
