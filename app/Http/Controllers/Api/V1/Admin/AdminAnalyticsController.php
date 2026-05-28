<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AnalyticsEvent;
use App\Models\PageView;
use App\Models\Visitor;
use App\Models\VisitorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class AdminAnalyticsController extends Controller
{
    #[OA\Get(
        path: "/api/v1/admin/analytics/dashboard",
        summary: "Admin Analytics Dashboard Stats",
        description: "Returns aggregated visitor statistics, traffic trends, and conversion rate for the dashboard.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Analytics"]
    )]
    #[OA\Parameter(name: "days", in: "query", schema: new OA\Schema(type: "integer", default: 30), description: "Number of days for historical trends")]
    #[OA\Response(
        response: 200,
        description: "Analytics statistics fetched successfully"
    )]
    public function dashboard(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $days = min(max($days, 1), 365); // Cap between 1 and 365 days
        
        $startDate = now()->subDays($days)->startOfDay();
        $todayStart = now()->startOfDay();

        // 1. Basic Stats (Today vs Period)
        $visitorsToday = Visitor::where('created_at', '>=', $todayStart)->count();
        $uniqueVisitorsPeriod = Visitor::where('created_at', '>=', $startDate)->count();
        $pageViewsPeriod = PageView::where('visited_at', '>=', $startDate)->count();
        $sessionsPeriod = VisitorSession::where('created_at', '>=', $startDate)->count();

        // 2. Top Countries
        $topCountries = Visitor::select('country', DB::raw('count(*) as count'))
            ->whereNotNull('country')
            ->where('created_at', '>=', $startDate)
            ->groupBy('country')
            ->orderByDesc('count')
            ->take(5)
            ->get();

        // 3. Top Cities
        $topCities = Visitor::select('city', 'country', DB::raw('count(*) as count'))
            ->whereNotNull('city')
            ->where('created_at', '>=', $startDate)
            ->groupBy('city', 'country')
            ->orderByDesc('count')
            ->take(5)
            ->get();

        // 4. Top Referrers (from Sessions)
        $topReferrers = VisitorSession::select(DB::raw('CASE 
                WHEN referrer LIKE "%google.com%" THEN "Google"
                WHEN referrer LIKE "%facebook.com%" THEN "Facebook"
                WHEN referrer LIKE "%t.co%" OR referrer LIKE "%twitter.com%" THEN "Twitter/X"
                WHEN referrer LIKE "%instagram.com%" THEN "Instagram"
                WHEN referrer IS NULL OR referrer = "" OR referrer = "direct" THEN "Direct / Bookmark"
                ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(SUBSTRING_INDEX(referrer, "/", 3), "://", -1), "/", 1), "?", 1)
            END as source'), DB::raw('count(*) as count'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('source')
            ->orderByDesc('count')
            ->take(5)
            ->get();

        // 5. Top Landing Pages
        $topLandingPages = VisitorSession::select('landing_page', DB::raw('count(*) as count'))
            ->whereNotNull('landing_page')
            ->where('created_at', '>=', $startDate)
            ->groupBy('landing_page')
            ->orderByDesc('count')
            ->take(5)
            ->get();

        // 6. Top Events
        $topEvents = AnalyticsEvent::select('event_name', DB::raw('count(*) as count'))
            ->where('visited_at', '>=', $startDate)
            ->groupBy('event_name')
            ->orderByDesc('count')
            ->take(8)
            ->get();

        // 7. Funnel & Conversion Rate (checkout_started -> order_completed)
        $totalSessions = VisitorSession::where('created_at', '>=', $startDate)->count();
        
        $checkoutStartedSessions = AnalyticsEvent::where('event_name', 'checkout_started')
            ->where('visited_at', '>=', $startDate)
            ->distinct('session_uuid')
            ->count('session_uuid');

        $orderCompletedSessions = AnalyticsEvent::where('event_name', 'order_completed')
            ->where('visited_at', '>=', $startDate)
            ->distinct('session_uuid')
            ->count('session_uuid');

        $conversionRate = $totalSessions > 0 
            ? round(($orderCompletedSessions / $totalSessions) * 100, 2) 
            : 0;

        // 8. Recent Visitors
        $recentVisitors = Visitor::latest()
            ->take(5)
            ->get()
            ->map(function ($visitor) {
                $latestSession = $visitor->sessions()->latest()->first();
                $latestView = $visitor->pageViews()->latest()->first();
                return [
                    'visitor_uuid'     => $visitor->visitor_uuid,
                    'browser'          => $visitor->browser,
                    'device'           => $visitor->device,
                    'operating_system' => $visitor->operating_system,
                    'country'          => $visitor->country,
                    'city'             => $visitor->city,
                    'last_active_at'   => $latestView ? $latestView->visited_at->toIso8601String() : $visitor->created_at->toIso8601String(),
                    'landing_page'     => $latestSession ? $latestSession->landing_page : null,
                    'utm_source'       => $latestSession ? $latestSession->utm_source : null,
                ];
            });

        // 9. Visits Chart By Day (last N days)
        $visitsByDay = PageView::select(DB::raw('DATE(visited_at) as date'), DB::raw('count(*) as page_views'), DB::raw('count(distinct session_uuid) as unique_sessions'))
            ->where('visited_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(visited_at)'))
            ->orderBy(DB::raw('DATE(visited_at)'))
            ->get();

        // 10. Events Chart By Day (last N days)
        $eventsByDay = AnalyticsEvent::select(DB::raw('DATE(visited_at) as date'), 'event_name', DB::raw('count(*) as count'))
            ->where('visited_at', '>=', $startDate)
            ->groupBy(DB::raw('DATE(visited_at)'), 'event_name')
            ->orderBy(DB::raw('DATE(visited_at)'))
            ->get()
            ->groupBy('date')
            ->map(function ($dayEvents) {
                return $dayEvents->pluck('count', 'event_name');
            });

        return response()->json([
            'success' => true,
            'message' => 'Analytics dashboard stats fetched.',
            'data'    => [
                'summary' => [
                    'visitors_today'    => $visitorsToday,
                    'unique_visitors'   => $uniqueVisitorsPeriod,
                    'total_page_views'  => $pageViewsPeriod,
                    'total_sessions'    => $sessionsPeriod,
                    'conversion_rate'   => $conversionRate,
                ],
                'funnel' => [
                    'total_sessions'    => $totalSessions,
                    'checkout_started'  => $checkoutStartedSessions,
                    'order_completed'   => $orderCompletedSessions,
                ],
                'top_countries'     => $topCountries,
                'top_cities'        => $topCities,
                'top_referrers'     => $topReferrers,
                'top_landing_pages' => $topLandingPages,
                'top_events'        => $topEvents,
                'recent_visitors'   => $recentVisitors,
                'charts' => [
                    'visits_by_day' => $visitsByDay,
                    'events_by_day' => $eventsByDay,
                ]
            ]
        ]);
    }
}
