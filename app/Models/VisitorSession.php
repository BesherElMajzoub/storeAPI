<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitorSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_uuid',
        'visitor_uuid',
        'user_id',
        'referrer',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'landing_page',
    ];

    /**
     * Get the visitor that owns the session.
     */
    public function visitor(): BelongsTo
    {
        return $this->belongsTo(Visitor::class, 'visitor_uuid', 'visitor_uuid');
    }

    /**
     * Get the user that owns the session (if authenticated).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the page views for this session.
     */
    public function pageViews(): HasMany
    {
        return $this->hasMany(PageView::class, 'session_uuid', 'session_uuid');
    }

    /**
     * Get the events for this session.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'session_uuid', 'session_uuid');
    }
}
