<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RejectOversizedRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $maxBytes = (int) config('app.max_request_bytes', 45 * 1024 * 1024);
        $contentLength = (int) $request->server('CONTENT_LENGTH', 0);

        if ($contentLength > $maxBytes) {
            return response()->json([
                'success' => false,
                'message' => 'Request payload is too large.',
                'data' => null,
                'errors' => null,
            ], 413);
        }

        return $next($request);
    }
}
