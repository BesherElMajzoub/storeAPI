<?php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

trait LogsActivity
{
    public function logActivity($action, $description = null, $changes = null)
    {
        AuditLog::create([
            'causer_id' => Auth::id(),
            'causer_type' => Auth::user() ? get_class(Auth::user()) : null,
            'action' => $action,
            'description' => $description,
            'ip_address' => request()->ip(),
            'changes' => $changes
        ]);
    }
}
