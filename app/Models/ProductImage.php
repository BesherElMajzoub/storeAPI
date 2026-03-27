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

    public function getUrlAttribute(): ?string
    {
        return $this->path ? asset('storage/' . $this->path) : null;
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
