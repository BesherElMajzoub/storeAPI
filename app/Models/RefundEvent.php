<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only history of a refund (rule R5). Rows are added, never changed:
 * the model refuses updates and deletes, and a database trigger does the same
 * where the hosting user is allowed to create one.
 */
class RefundEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['details' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Refund events cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Refund events cannot be deleted.'));
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<RefundAttempt, $this> */
    public function attempt(): BelongsTo
    {
        return $this->belongsTo(RefundAttempt::class, 'attempt_id');
    }
}
