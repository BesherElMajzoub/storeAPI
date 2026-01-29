<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureAdminRole
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (!$user || !$user->hasRole('Admin')) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden.',
                'data' => null,
                'errors' => null,
            ], 403);
        }

        return $next($request);
    }
}
