<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'refund_status' => 'none',
    ];

    protected $fillable = [
        'order_number', 'user_id', 'coupon_id', 'status', 'payment_status',
        'subtotal', 'tax', 'shipping_cost', 'carrier_shipping_cost', 'free_shipping_reason', 'discount', 'refunded_amount', 'refund_status', 'total',
        'coupon_code', 'shipping_address', 'billing_address', 'notes',
        'stripe_session_id', 'stripe_payment_intent_id', 'authorized_at',
        'paid_at', 'cancelled_at', 'refunded_at', 'stock_reserved_at', 'stock_released_at',
        'easypost_shipment_id', 'shipping_rate_id', 'shipping_carrier', 'shipping_service',
        'tracking_number', 'shipment_status', 'tracking_url', 'label_url', 'label_path', 'shipped_at', 'fulfillment_started_at',
        'fulfillment_hold', 'capture_attempts', 'capture_failed_at',
        'estimated_delivery', 'tracking_events',
    ];

    protected $casts = [
        'shipping_address' => 'array',
        'billing_address' => 'array',
        'subtotal' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping_cost' => 'decimal:2',
        'carrier_shipping_cost' => 'decimal:2',
        'discount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'authorized_at' => 'datetime',
        'paid_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refunded_at' => 'datetime',
        'stock_reserved_at' => 'datetime',
        'stock_released_at' => 'datetime',
        'shipped_at' => 'datetime',
        'fulfillment_started_at' => 'datetime',
        'fulfillment_hold' => 'boolean',
        'capture_attempts' => 'integer',
        'capture_failed_at' => 'datetime',
        'estimated_delivery' => 'date:Y-m-d',
        'tracking_events' => 'array',
    ];

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isAuthorized(): bool
    {
        return $this->payment_status === 'authorized';
    }

    public function isRefunded(): bool
    {
        return $this->payment_status === 'refunded';
    }

    /**
     * When the customer's direct (no-approval) cancellation window closes, or
     * null when the order is not directly cancellable at all. Unpaid orders
     * count from creation; paid/authorized orders count from checkout and
     * stop being cancellable once a label purchase has started.
     */
    public function customerCancelDeadline(): ?Carbon
    {
        if ($this->status === 'pending_payment' && $this->payment_status === 'unpaid') {
            return $this->created_at?->copy()->addMinutes(self::directCancelWindowMinutes());
        }

        if ($this->status === 'processing'
            && in_array($this->payment_status, ['authorized', 'paid'], true)
            && ! $this->tracking_number
            && ! $this->fulfillment_started_at) {
            return ($this->authorized_at ?? $this->paid_at)?->copy()->addMinutes(self::directCancelWindowMinutes());
        }

        return null;
    }

    /** Minutes after checkout during which a customer may cancel without admin approval. */
    public static function directCancelWindowMinutes(): int
    {
        return max(1, (int) config('orders.direct_cancel_window_minutes', 180));
    }

    public function canBeCancelledByCustomer(): bool
    {
        $deadline = $this->customerCancelDeadline();

        return $deadline !== null && now()->lt($deadline);
    }

    /**
     * How the customer can cancel right now, so the frontend never computes
     * the window itself. mode: direct (POST /cancel), request (submit a
     * cancellation request) or none.
     *
     * @return array{mode: string, reason: string, direct_until: ?Carbon}
     */
    public function customerCancellation(bool $hasPendingRequest = false): array
    {
        if (in_array($this->status, ['shipped', 'delivered', 'cancelled', 'refunded'], true)) {
            return ['mode' => 'none', 'reason' => $this->status, 'direct_until' => null];
        }

        // A held payment that could not be captured: the shop contacts the customer.
        if ($this->fulfillment_hold && $this->payment_status === 'failed') {
            return ['mode' => 'none', 'reason' => 'payment_failed', 'direct_until' => null];
        }

        $deadline = $this->customerCancelDeadline();

        if ($deadline !== null && now()->lt($deadline)) {
            return ['mode' => 'direct', 'reason' => 'within_window', 'direct_until' => $deadline];
        }

        if ($hasPendingRequest) {
            return ['mode' => 'none', 'reason' => 'request_pending', 'direct_until' => null];
        }

        $reason = match (true) {
            (bool) $this->tracking_number => 'label_purchased',
            $this->fulfillment_started_at !== null => 'fulfillment_in_progress',
            $deadline !== null => 'window_expired',
            default => 'not_directly_cancellable',
        };

        return ['mode' => 'request', 'reason' => $reason, 'direct_until' => null];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(Payment::class);
    }

    public function cancellationRequest(): HasOne
    {
        return $this->hasOne(OrderCancellationRequest::class)->latestOfMany();
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
        if (! $term) {
            return $query;
        }

        return $query->where('order_number', 'like', '%'.$term.'%');
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
