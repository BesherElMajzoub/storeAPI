<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OtpCode extends Model
{
    protected $fillable = [
        'identifier',
        'purpose',
        'channel',
        'code_hash',
        'expires_at',
        'attempts',
        'max_attempts',
        'last_sent_at',
        'sent_count',
        'sent_count_date',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'last_sent_at' => 'datetime',
        'sent_count_date' => 'date',
        'consumed_at' => 'datetime',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'sent_count' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->whereColumn('attempts', '<', 'max_attempts');
    }

    public function scopeForIdentifier($query, string $identifier, string $purpose, string $channel = 'email')
    {
        return $query->where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->where('channel', $channel);
    }
}
