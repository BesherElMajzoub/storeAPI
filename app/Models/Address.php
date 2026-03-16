<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class Address extends Model
{
    protected $fillable = [
        'user_id',
        // Legacy fields (kept for backward compatibility with orders)
        'type',
        'name',
        'line1',
        'line2',
        'state',
        // New address management fields
        'label',       // home, work, other
        'full_name',
        'phone',
        'country',
        'city',
        'area',
        'street',
        'building',
        'floor',
        'apartment',
        'postal_code',
        'notes',
        'is_default',
    ];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    // ---------- Relationships ----------

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // ---------- Scopes ----------

    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }

    // ---------- Business Logic ----------

    /**
     * Set this address as default and remove default from all other user addresses.
     */
    public function setAsDefault(): void
    {
        // Remove default from all other addresses of the same user
        static::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }
}
