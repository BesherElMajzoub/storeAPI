<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'name', 'sku', 'price', 'stock_qty', 'attributes',
        'weight_oz', 'length_in', 'width_in', 'height_in',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price' => 'decimal:2',
        'weight_oz' => 'decimal:2',
        'length_in' => 'decimal:2',
        'width_in' => 'decimal:2',
        'height_in' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
