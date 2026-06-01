<?php

namespace App\Http\Middleware;

use App\Helpers\UserAgentParser;
use App\Jobs\LogPageViewJob;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class TrackVisitorSession
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // 1. Check if analytics is enabled
        if (!config('analytics.enabled', true)) {
            return $next($request);
        }

        // 2. Exclude non-trackable paths (admin, api auth, static, health)
        if ($this->shouldExclude($request)) {
            return $next($request);
        }

        // 3. User agent parsing & bot detection
        $userAgent = $request->header('User-Agent');
        $uaData = UserAgentParser::parse($userAgent);

        if (config('analytics.ignore_bots', true) && $uaData['is_bot']) {
            return $next($request);
        }

        // 4. Resolve or generate visitor and session UUIDs
        $visitorUuid = $this->cleanUuid($request->header('X-Visitor-ID') ?? $request->cookie('visitor_id'));
        $sessionUuid = $this->cleanUuid($request->header('X-Session-ID') ?? $request->cookie('visitor_session_id'));

        // 5. Gather tracking payload
        $payload = [
            'visitor_uuid'     => $visitorUuid,
            'session_uuid'     => $sessionUuid,
            'user_id'          => $request->user()?->id,
            'ip'               => $request->ip(),
            'url'              => $request->fullUrl(),
            'referrer'         => $request->header('Referer'),
            'user_agent'       => $userAgent,
            'browser'          => $uaData['browser'],
            'device'           => $uaData['device'],
            'operating_system' => $uaData['operating_system'],
            
            // UTM tracking parameters
            'utm_source'       => $request->query('utm_source'),
            'utm_medium'       => $request->query('utm_medium'),
            'utm_campaign'     => $request->query('utm_campaign'),
            'utm_term'         => $request->query('utm_term'),
            'utm_content'      => $request->query('utm_content'),
            
            'visited_at'       => now(),
        ];

        // 6. Dispatch the LogPageViewJob to be executed asynchronously
        dispatch(new LogPageViewJob($payload));

        // 7. Proceed with the request
        $response = $next($request);

        // 8. Attach visitor/session cookies & headers to response
        if ($response instanceof Response) {
            // Long-lived cookie for visitor (1 year)
            if (!$request->cookie('visitor_id') && !$request->header('X-Visitor-ID')) {
                $response->headers->setCookie(cookie('visitor_id', $visitorUuid, 525600, null, null, false, false));
            }
            // Session cookie for visitor session (ends when browser closed)
            if (!$request->cookie('visitor_session_id') && !$request->header('X-Session-ID')) {
                $response->headers->setCookie(cookie('visitor_session_id', $sessionUuid, 0, null, null, false, false));
            }

            // Also attach headers to help SPA tracking client
            $response->headers->set('X-Visitor-ID', $visitorUuid);
            $response->headers->set('X-Session-ID', $sessionUuid);
        }

        return $response;
    }

    /**
     * Determine if the request should be excluded from analytics tracking.
     */
    private function shouldExclude(Request $request): bool
    {
        // Ignore static assets / files
        if (preg_match('/\.(css|js|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|otf|webp|map)$/i', $request->path())) {
            return true;
        }

        // Exclude Laravel internal paths (Telescope, Swagger, health check)
        $path = '/' . ltrim($request->path(), '/');
        $exclusions = [
            '/up', // default health check
            '/telescope',
            '/api/documentation', // swagger
            '/api/oauth',
        ];

        foreach ($exclusions as $exclusion) {
            if (str_starts_with($path, $exclusion)) {
                return true;
            }
        }

        // Exclude Admin routes and Auth routes
        if ($request->is('admin*') || $request->is('api/v1/admin*') || $request->is('api/v1/auth*')) {
            return true;
        }

        return false;
    }

    /**
     * Clean and validate a UUID. If it is encrypted or too long, decrypt or regenerate it.
     */
    private function cleanUuid(?string $uuid): string
    {
        if (empty($uuid)) {
            return (string) \Illuminate\Support\Str::uuid();
        }

        // If the UUID looks like an encrypted Laravel cookie (it is long)
        if (strlen($uuid) > 36) {
            try {
                // Try to decrypt it manually
                $decrypted = decrypt($uuid, false);
                if ($decrypted && strlen($decrypted) === 36) {
                    return $decrypted;
                }
            } catch (\Throwable $e) {
                // Ignore and fall back to fresh UUID
            }
            
            return (string) \Illuminate\Support\Str::uuid();
        }

        return $uuid;
    }
}
