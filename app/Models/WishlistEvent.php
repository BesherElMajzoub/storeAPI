<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
