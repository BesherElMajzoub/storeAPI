<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShippingRateQuote extends Model
{
    protected $fillable = [
        'rate_id', 'shipment_id', 'carrier', 'service', 'amount', 'currency',
        'eta_days', 'address_hash', 'items_hash', 'parcel_hash', 'expires_at',
        'consumed_at', 'order_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'eta_days' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
