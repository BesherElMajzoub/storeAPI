<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    protected $fillable = [
        'causer_id', 'causer_type', 'action', 'description',
        'ip_address', 'changes',
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }
}
