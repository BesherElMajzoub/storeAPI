<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_uuid',
        'visitor_uuid',
        'user_id',
        'event_name',
        'event_metadata',
        'visited_at',
    ];

    protected $casts = [
        'event_metadata' => 'array',
        'visited_at'     => 'datetime',
    ];

    /**
     * Get the session.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(VisitorSession::class, 'session_uuid', 'session_uuid');
    }

    /**
     * Get the visitor.
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_uuid', 'visitor_uuid');
    }

    /**
     * Get the user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
