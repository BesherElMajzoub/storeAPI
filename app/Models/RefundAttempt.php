<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One attempt to return money for an order: a Stripe refund, a refund the
 * owner sent by Zelle or PayPal, or one found in Stripe that the shop did not
 * start. The order's refund state is calculated from these rows.
 */
class RefundAttempt extends Model
{
    public const CHANNEL_STRIPE = 'stripe';

    public const CHANNEL_MANUAL = 'manual';

    public const CHANNEL_RECONCILIATION = 'reconciliation';

    public const REQUESTED = 'requested';

    public const PENDING = 'pending';

    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    public const RECORDED = 'recorded';

    public const VOIDED = 'voided';

    /** Statuses that count as money returned. */
    public const DONE = [self::SUCCEEDED, self::RECORDED];

    /** Statuses of an attempt still in progress (rule R2 allows one per order). */
    public const OPEN = [self::REQUESTED, self::PENDING];

    /** Stripe statuses only move forward (rule R6); a bank can still fail a refund that already succeeded. */
    private const RANK = [self::REQUESTED => 0, self::PENDING => 1, self::SUCCEEDED => 2, self::FAILED => 3];

    protected $guarded = [];

    protected $casts = [
        'amount_cents' => 'integer',
        'attempt_no' => 'integer',
        'retryable' => 'boolean',
        'next_retry_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    protected $hidden = ['stripe_idempotency_key', 'open_order_id'];

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<RefundEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(RefundEvent::class, 'attempt_id')->orderBy('id');
    }

    public function isManual(): bool
    {
        return $this->channel === self::CHANNEL_MANUAL;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    public function canMoveTo(string $status): bool
    {
        return isset(self::RANK[$this->status], self::RANK[$status]) && self::RANK[$status] > self::RANK[$this->status];
    }
}
