<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        // Factory-created in-memory users may not contain database defaults yet;
        // only an explicit false value represents a disabled persisted account.
        if (! $user || $user->is_active === false) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
                'data' => null,
                'errors' => null,
            ], 401);
        }

        return $next($request);
    }
}
