<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'causer_id', 'causer_type', 'action', 'description', 
        'ip_address', 'changes'
    ];

    protected $casts = [
        'changes' => 'array',
    ];

    public function causer()
    {
        return $this->morphTo();
    }
}
