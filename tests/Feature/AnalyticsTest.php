<?php

namespace Tests\Feature;

use App\Jobs\LogEventJob;
use App\Jobs\LogPageViewJob;
use App\Models\AnalyticsEvent;
use App\Models\PageView;
use App\Models\Role;
use App\Models\User;
use App\Models\Visitor;
use App\Models\VisitorSession;
use App\Services\GeoLocationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test public route visitor tracking dispatches LogPageViewJob.
     */
    public function test_middleware_dispatches_log_page_view_job(): void
    {
        Bus::fake();

        // Hit a public route
        $response = $this->get('/');

        $response->assertStatus(200);

        // Assert job was dispatched
        Bus::assertDispatched(LogPageViewJob::class);

        // Assert visitor and session cookies/headers are returned
        $response->assertHeader('X-Visitor-ID');
        $response->assertHeader('X-Session-ID');
    }

    /**
     * Test analytics event tracking dispatches LogEventJob.
     */
    public function test_analytics_event_endpoint_dispatches_log_event_job(): void
    {
        Bus::fake();

        $response = $this->postJson('/api/v1/analytics/event', [
            'event_name' => 'whatsapp_clicked',
            'url'        => 'http://localhost/products/1',
            'visitor_uuid' => (string) Str::uuid(),
            'session_uuid' => (string) Str::uuid(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Event queued successfully.',
            ]);

        Bus::assertDispatched(LogEventJob::class);
    }

    /**
     * Test LogPageViewJob processes visitor, session, and page view correctly.
     */
    public function test_log_page_view_job_persists_data(): void
    {
        $visitorUuid = (string) Str::uuid();
        $sessionUuid = (string) Str::uuid();

        $payload = [
            'visitor_uuid'     => $visitorUuid,
            'session_uuid'     => $sessionUuid,
            'user_id'          => null,
            'ip'               => '8.8.8.8',
            'url'              => 'http://localhost/test',
            'referrer'         => 'http://google.com',
            'user_agent'       => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'browser'          => 'Chrome',
            'device'           => 'desktop',
            'operating_system' => 'Windows',
            'utm_source'       => 'google',
            'utm_medium'       => 'cpc',
            'utm_campaign'     => 'spring_sale',
            'visited_at'       => now(),
        ];

        // Disable GeoIP check to speed up tests and avoid external HTTP queries
        config(['analytics.geoip_enabled' => false]);

        $job = new LogPageViewJob($payload);
        app()->call([$job, 'handle']);

        // Verify visitor, session, and page view records exist in the database
        $this->assertDatabaseHas('visitors', [
            'visitor_uuid' => $visitorUuid,
            'browser'      => 'Chrome',
            'device'       => 'desktop',
        ]);

        $this->assertDatabaseHas('visitor_sessions', [
            'session_uuid' => $sessionUuid,
            'utm_source'   => 'google',
            'utm_campaign' => 'spring_sale',
        ]);

        $this->assertDatabaseHas('page_views', [
            'session_uuid' => $sessionUuid,
            'url'          => 'http://localhost/test',
        ]);
    }

    /**
     * Test LogPageViewJob avoids duplicate page views within 15 seconds.
     */
    public function test_log_page_view_job_avoids_duplicates(): void
    {
        $visitorUuid = (string) Str::uuid();
        $sessionUuid = (string) Str::uuid();

        $payload = [
            'visitor_uuid'     => $visitorUuid,
            'session_uuid'     => $sessionUuid,
            'user_id'          => null,
            'ip'               => '8.8.8.8',
            'url'              => 'http://localhost/test-dup',
            'referrer'         => null,
            'user_agent'       => 'Mozilla',
            'browser'          => 'Chrome',
            'device'           => 'desktop',
            'operating_system' => 'Windows',
            'visited_at'       => now(),
        ];

        config(['analytics.geoip_enabled' => false]);

        // Run job first time
        $job = new LogPageViewJob($payload);
        app()->call([$job, 'handle']);

        // Run job second time within 15 seconds
        $job2 = new LogPageViewJob($payload);
        app()->call([$job2, 'handle']);

        // Count page views - should only be 1
        $this->assertEquals(1, PageView::where('session_uuid', $sessionUuid)->count());
    }

    /**
     * Test LogEventJob persists custom events in the database.
     */
    public function test_log_event_job_persists_data(): void
    {
        $visitorUuid = (string) Str::uuid();
        $sessionUuid = (string) Str::uuid();

        $payload = [
            'visitor_uuid'   => $visitorUuid,
            'session_uuid'   => $sessionUuid,
            'user_id'        => null,
            'ip'             => '8.8.8.8',
            'url'            => 'http://localhost/checkout',
            'referrer'       => null,
            'user_agent'     => 'Mozilla',
            'browser'        => 'Chrome',
            'device'         => 'desktop',
            'operating_system' => 'Windows',
            'event_name'     => 'checkout_started',
            'event_metadata' => ['total' => 250.00],
            'visited_at'     => now(),
        ];

        config(['analytics.geoip_enabled' => false]);

        $job = new LogEventJob($payload);
        app()->call([$job, 'handle']);

        $this->assertDatabaseHas('analytics_events', [
            'session_uuid' => $sessionUuid,
            'event_name'   => 'checkout_started',
        ]);
    }

    /**
     * Test admin analytics dashboard endpoint.
     */
    public function test_admin_analytics_dashboard_endpoint(): void
    {
        // 1. Create admin user and authenticate
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin'], ['label' => 'Admin']);
        $admin->roles()->attach($role);

        // 2. Generate seed data in database
        $visitor = Visitor::create([
            'visitor_uuid' => (string) Str::uuid(),
            'ip_hash'      => hash('sha256', '8.8.8.8'),
            'browser'      => 'Firefox',
            'device'       => 'mobile',
            'country'      => 'Saudi Arabia',
            'city'         => 'Riyadh',
        ]);

        $session = VisitorSession::create([
            'session_uuid' => (string) Str::uuid(),
            'visitor_uuid' => $visitor->visitor_uuid,
            'referrer'     => 'http://google.com',
            'utm_source'   => 'google',
        ]);

        PageView::create([
            'session_uuid' => $session->session_uuid,
            'visitor_uuid' => $visitor->visitor_uuid,
            'url'          => 'http://localhost/',
            'visited_at'   => now(),
        ]);

        AnalyticsEvent::create([
            'session_uuid' => $session->session_uuid,
            'visitor_uuid' => $visitor->visitor_uuid,
            'event_name'   => 'order_completed',
            'visited_at'   => now(),
        ]);

        // 3. Request admin dashboard as authenticated admin
        $response = $this->actingAs($admin)
            ->getJson('/api/v1/admin/analytics/dashboard');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'summary' => [
                        'visitors_today',
                        'unique_visitors',
                        'total_page_views',
                        'total_sessions',
                        'conversion_rate',
                    ],
                    'funnel' => [
                        'total_sessions',
                        'checkout_started',
                        'order_completed',
                    ],
                    'top_countries',
                    'top_cities',
                    'top_referrers',
                    'top_landing_pages',
                    'top_events',
                    'recent_visitors',
                    'charts' => [
                        'visits_by_day',
                        'events_by_day',
                    ]
                ]
            ]);
    }
}
