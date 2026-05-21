<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number', 'user_id', 'status', 'payment_status',
        'subtotal', 'tax', 'shipping_cost', 'discount', 'total',
        'coupon_code', 'shipping_address', 'billing_address', 'notes',
        'stripe_session_id', 'stripe_payment_intent_id',
        'paid_at', 'cancelled_at', 'refunded_at',
    ];

    protected $casts = [
        'shipping_address'         => 'array',
        'billing_address'          => 'array',
        'subtotal'                 => 'decimal:2',
        'tax'                      => 'decimal:2',
        'shipping_cost'            => 'decimal:2',
        'discount'                 => 'decimal:2',
        'total'                    => 'decimal:2',
        'paid_at'                  => 'datetime',
        'cancelled_at'             => 'datetime',
        'refunded_at'              => 'datetime',
    ];

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isRefunded(): bool
    {
        return $this->payment_status === 'refunded';
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    public function cancellationRequest()
    {
        return $this->hasOne(\App\Models\OrderCancellationRequest::class);
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    public function scopeStatus($query, $status)
    {
        if (is_array($status)) {
            return $query->whereIn('status', $status);
        }

        if (is_string($status) && str_contains($status, ',')) {
            return $query->whereIn('status', array_map('trim', explode(',', $status)));
        }

        return $query->where('status', $status);
    }

    public function scopePaymentStatus($query, $status)
    {
        if (is_array($status)) {
            return $query->whereIn('payment_status', $status);
        }

        if (is_string($status) && str_contains($status, ',')) {
            return $query->whereIn('payment_status', array_map('trim', explode(',', $status)));
        }

        return $query->where('payment_status', $status);
    }

    public function scopeSearch($query, ?string $term)
    {
        if (!$term) {
            return $query;
        }

        return $query->where('order_number', 'like', '%' . $term . '%');
    }

    public function scopeDateRange($query, ?string $from, ?string $to)
    {
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
    }
}
