<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WishlistEvent extends Model
{
    // This table only has created_at (no updated_at)
    const UPDATED_AT = null;

    protected $table = 'wishlist_events';

    protected $fillable = [
        'user_id',
        'product_id',
        'action',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
