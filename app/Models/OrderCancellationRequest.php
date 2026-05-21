<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderCancellationRequest extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'reason',
        'status',
        'admin_id',
        'admin_note',
        'decided_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    // ── Relations ────────────────────────────────────────────────────────────

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function admin()
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    public function scopeByStatus($query, ?string $status)
    {
        if ($status) {
            $query->where('status', $status);
        }
        return $query;
    }
}
