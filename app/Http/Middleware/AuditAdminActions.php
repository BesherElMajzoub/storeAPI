<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditAdminActions
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) && $request->user()) {
            AuditLog::create([
                'causer_id' => $request->user()->id,
                'causer_type' => $request->user()::class,
                'action' => strtolower($request->method()).'_admin_api',
                'description' => $request->path(),
                'ip_address' => $request->ip(),
                'changes' => ['status_code' => $response->getStatusCode()],
            ]);
        }

        return $response;
    }
}
