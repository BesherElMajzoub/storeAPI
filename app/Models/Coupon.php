<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $fillable = [
        'code', 'type', 'value', 'minimum_order_amount', 'maximum_discount_amount',
        'usage_limit', 'used_count', 'usage_limit_per_user', 'starts_at', 'expires_at',
        'is_active'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'value' => 'decimal:2',
        'minimum_order_amount' => 'decimal:2',
        'maximum_discount_amount' => 'decimal:2',
        'usage_limit_per_user' => 'integer',
    ];

    public function usages()
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function isValid()
    {
        return $this->isAvailable();
    }

    public function getRemainingUsesAttribute(): ?int
    {
        if ($this->usage_limit === null) {
            return null;
        }
        return max(0, $this->usage_limit - $this->used_count);
    }

    public function getIsExpiredAttribute(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function getIsStartedAttribute(): bool
    {
        return !$this->starts_at || $this->starts_at->isPast();
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->is_active 
            && $this->is_started 
            && !$this->is_expired 
            && ($this->usage_limit === null || $this->used_count < $this->usage_limit);
    }

    public function remainingUses(): ?int
    {
        return $this->remaining_uses;
    }

    public function isExpired(): bool
    {
        return $this->is_expired;
    }

    public function isStarted(): bool
    {
        return $this->is_started;
    }

    public function isAvailable(): bool
    {
        return $this->is_available;
    }
}
