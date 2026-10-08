<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * What a customer pays for this variant. A variant without its own price
     * costs what the product costs; a discounted product gives the same
     * percentage off the variant's price. Orders, carts and coupons use this.
     */
    public function finalPriceFor(Product $product): float
    {
        if ($this->price === null) {
            return (float) $product->final_price;
        }

        $price = (float) $this->price;
        $base = (float) $product->price;
        $final = (float) $product->final_price;

        return $base > 0 && $final < $base ? round($price * ($final / $base), 2) : $price;
    }
}
