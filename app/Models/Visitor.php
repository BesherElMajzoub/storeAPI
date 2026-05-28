<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Visitor extends Model
{
    use HasFactory;

    protected $fillable = [
        'visitor_uuid',
        'ip_hash',
        'user_id',
        'browser',
        'device',
        'operating_system',
        'user_agent',
        'country',
        'city',
        'region',
        'latitude',
        'longitude',
    ];

    /**
     * Get the user that owns the visitor profile (if authenticated).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the sessions for the visitor.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(VisitorSession::class, 'visitor_uuid', 'visitor_uuid');
    }

    /**
     * Get the page views for the visitor.
     */
    public function pageViews(): HasMany
    {
        return $this->hasMany(PageView::class, 'visitor_uuid', 'visitor_uuid');
    }

    /**
     * Get the events for the visitor.
     */
    public function events(): HasMany
    {
        return $this->hasMany(AnalyticsEvent::class, 'visitor_uuid', 'visitor_uuid');
    }
}
