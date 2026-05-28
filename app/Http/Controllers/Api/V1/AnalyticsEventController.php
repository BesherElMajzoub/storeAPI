<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Helpers\UserAgentParser;
use App\Jobs\LogEventJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AnalyticsEventController extends Controller
{
    /**
     * Track a custom client-side analytics event.
     */
    public function track(Request $request): JsonResponse
    {
        if (!config('analytics.enabled', true)) {
            return response()->json(['success' => false, 'message' => 'Tracking disabled.'], 200);
        }

        // Validate the request payload
        $validated = $request->validate([
            'event_name' => [
                'required',
                'string',
                Rule::in([
                    'page_view',
                    'whatsapp_clicked',
                    'phone_clicked',
                    'contact_form_submitted',
                    'order_started',
                    'order_completed',
                    'product_viewed',
                    'add_to_cart',
                    'checkout_started',
                ])
            ],
            'event_metadata' => 'nullable|array',
            'visitor_uuid'   => 'nullable|string|uuid',
            'session_uuid'   => 'nullable|string|uuid',
            'url'            => 'nullable|string|url',
        ]);

        // Bot Detection
        $userAgent = $request->header('User-Agent');
        $uaData = UserAgentParser::parse($userAgent);

        if (config('analytics.ignore_bots', true) && $uaData['is_bot']) {
            return response()->json(['success' => false, 'message' => 'Bot request ignored.'], 200);
        }

        // Extract or generate Visitor and Session UUIDs
        $visitorUuid = $validated['visitor_uuid']
            ?? $request->header('X-Visitor-ID')
            ?? $request->cookie('visitor_id')
            ?? (string) Str::uuid();

        $sessionUuid = $validated['session_uuid']
            ?? $request->header('X-Session-ID')
            ?? $request->cookie('visitor_session_id')
            ?? (string) Str::uuid();

        $url = $validated['url'] ?? $request->header('Referer') ?? $request->fullUrl();

        // Build Payload
        $payload = [
            'visitor_uuid'     => $visitorUuid,
            'session_uuid'     => $sessionUuid,
            'user_id'          => $request->user()?->id,
            'ip'               => $request->ip(),
            'url'              => $url,
            'referrer'         => $request->header('Referer'),
            'user_agent'       => $userAgent,
            'browser'          => $uaData['browser'],
            'device'           => $uaData['device'],
            'operating_system' => $uaData['operating_system'],
            
            // UTM tracking parameters (attempt to extract from URL if query params exist)
            'utm_source'       => $request->query('utm_source') ?? $this->getQueryParam($url, 'utm_source'),
            'utm_medium'       => $request->query('utm_medium') ?? $this->getQueryParam($url, 'utm_medium'),
            'utm_campaign'     => $request->query('utm_campaign') ?? $this->getQueryParam($url, 'utm_campaign'),
            'utm_term'         => $request->query('utm_term') ?? $this->getQueryParam($url, 'utm_term'),
            'utm_content'      => $request->query('utm_content') ?? $this->getQueryParam($url, 'utm_content'),

            'event_name'       => $validated['event_name'],
            'event_metadata'   => $validated['event_metadata'] ?? [],
            'visited_at'       => now(),
        ];

        // Dispatch queued job
        dispatch(new LogEventJob($payload));

        return response()->json([
            'success' => true,
            'message' => 'Event queued successfully.',
            'data'    => [
                'visitor_uuid' => $visitorUuid,
                'session_uuid' => $sessionUuid,
            ]
        ]);
    }

    /**
     * Helper method to parse query parameters from a URL string.
     */
    private function getQueryParam(?string $url, string $paramName): ?string
    {
        if (empty($url)) {
            return null;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (empty($query)) {
            return null;
        }

        parse_str($query, $params);

        return $params[$paramName] ?? null;
    }
}
