<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'product_id', 'order_id', 'rating', 'comment',
        'is_approved', 'is_verified_purchase', 'admin_note', 'ip_address',
    ];

    protected $casts = [
        'is_approved'          => 'boolean',
        'is_verified_purchase' => 'boolean',
    ];

    // ─── Relationships ────────────────────────────────────────────────────────

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopePending($query)
    {
        return $query->where('is_approved', false)->whereNull('admin_note');
    }

    public function scopeRejected($query)
    {
        return $query->where('is_approved', false)->whereNotNull('admin_note');
    }
}
