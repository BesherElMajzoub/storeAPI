<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DemoAnalyticsSeeder extends DemoSeeder
{
    public const VISITOR_COUNT = 50;

    public const SESSION_COUNT = 72;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $visitorIds = array_map(fn (int $index) => $this->visitorUuid($index), range(1, self::VISITOR_COUNT));
        DB::table('visitors')->whereIn('visitor_uuid', $visitorIds)->delete();

        $users = User::where('email', 'like', '%@demo.test')->orderBy('id')->get();
        $productSlugs = Product::published()->orderBy('id')->pluck('slug')->all();
        $locations = [
            ['United States', 'New York', 'New York', 40.7128, -74.0060],
            ['United States', 'Los Angeles', 'California', 34.0522, -118.2437],
            ['United States', 'Austin', 'Texas', 30.2672, -97.7431],
            ['Canada', 'Toronto', 'Ontario', 43.6532, -79.3832],
            ['United Kingdom', 'London', 'England', 51.5072, -0.1276],
            ['United Arab Emirates', 'Dubai', 'Dubai', 25.2048, 55.2708],
        ];
        $browsers = ['Chrome', 'Safari', 'Firefox', 'Edge'];
        $devices = ['mobile', 'desktop', 'tablet'];
        $systems = ['iOS', 'Android', 'Windows 11', 'macOS'];
        $visitors = [];

        for ($index = 1; $index <= self::VISITOR_COUNT; $index++) {
            $createdAt = now()->subDays(($index - 1) % 30)->subMinutes($index * 3);
            [$country, $city, $region, $latitude, $longitude] = $locations[($index - 1) % count($locations)];
            $visitors[] = [
                'visitor_uuid' => $this->visitorUuid($index),
                'ip_hash' => hash('sha256', 'demo-ip-'.$index),
                'user_id' => $index % 3 === 0 ? $users[($index - 1) % $users->count()]->id : null,
                'browser' => $browsers[($index - 1) % count($browsers)],
                'device' => $devices[($index - 1) % count($devices)],
                'operating_system' => $systems[($index - 1) % count($systems)],
                'user_agent' => 'DemoBrowser/'.$index.' (frontend analytics fixture)',
                'country' => $country,
                'city' => $city,
                'region' => $region,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }
        DB::table('visitors')->insert($visitors);

        $referrers = [null, 'https://www.google.com/search?q=otantik+queen', 'https://www.instagram.com/', 'https://www.facebook.com/', 'https://t.co/demo', 'https://example.com/fashion-blog'];
        $sources = [null, 'google', 'instagram', 'facebook', 'newsletter', 'influencer'];
        $sessions = [];
        $pageViews = [];
        $events = [];

        for ($index = 1; $index <= self::SESSION_COUNT; $index++) {
            $visitorIndex = (($index - 1) % self::VISITOR_COUNT) + 1;
            $visitorUuid = $this->visitorUuid($visitorIndex);
            $sessionUuid = $this->sessionUuid($index);
            $userId = $index % 4 === 0 ? $users[($index - 1) % $users->count()]->id : null;
            $createdAt = now()->subDays(($index - 1) % 30)->subMinutes($index * 11);
            $landingPage = $index % 5 === 0 && $productSlugs !== []
                ? '/products/'.$productSlugs[$index % count($productSlugs)]
                : ['/', '/products', '/categories/demo-women', '/track-order'][$index % 4];
            $source = $sources[$index % count($sources)];
            $sessions[] = [
                'session_uuid' => $sessionUuid,
                'visitor_uuid' => $visitorUuid,
                'user_id' => $userId,
                'referrer' => $referrers[$index % count($referrers)],
                'utm_source' => $source,
                'utm_medium' => $source ? ($source === 'newsletter' ? 'email' : 'social') : null,
                'utm_campaign' => $source ? 'demo-autumn-launch' : null,
                'utm_term' => $index % 8 === 0 ? 'luxury gifts' : null,
                'utm_content' => $index % 9 === 0 ? 'hero-banner-a' : null,
                'landing_page' => $landingPage,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            $viewCount = 4 + ($index % 5);
            for ($view = 0; $view < $viewCount; $view++) {
                $url = match ($view % 5) {
                    0 => $landingPage,
                    1 => '/products',
                    2 => '/products/'.$productSlugs[(($index * 3) + $view) % count($productSlugs)],
                    3 => '/cart',
                    default => '/checkout',
                };
                $visitedAt = $createdAt->copy()->addMinutes($view * 3);
                $pageViews[] = [
                    'session_uuid' => $sessionUuid,
                    'visitor_uuid' => $visitorUuid,
                    'user_id' => $userId,
                    'url' => $url,
                    'referrer' => $view === 0 ? $referrers[$index % count($referrers)] : ($pageViews[array_key_last($pageViews)]['url'] ?? null),
                    'visited_at' => $visitedAt,
                    'created_at' => $visitedAt,
                    'updated_at' => $visitedAt,
                ];
            }

            $eventNames = ['product_viewed', 'wishlist_added', 'cart_added'];
            if ($index % 3 === 0) {
                $eventNames[] = 'checkout_started';
            }
            if ($index % 7 === 0) {
                $eventNames[] = 'order_completed';
            }
            foreach ($eventNames as $eventOffset => $eventName) {
                $visitedAt = $createdAt->copy()->addMinutes(2 + ($eventOffset * 4));
                $events[] = [
                    'session_uuid' => $sessionUuid,
                    'visitor_uuid' => $visitorUuid,
                    'user_id' => $userId,
                    'event_name' => $eventName,
                    'event_metadata' => json_encode([
                        'demo' => true,
                        'product_slug' => $productSlugs[(($index * 2) + $eventOffset) % count($productSlugs)],
                        'quantity' => ($index % 3) + 1,
                        'value' => round(19.95 + (($index * 7.25) % 300), 2),
                    ]),
                    'visited_at' => $visitedAt,
                    'created_at' => $visitedAt,
                    'updated_at' => $visitedAt,
                ];
            }
        }

        DB::table('visitor_sessions')->insert($sessions);
        foreach (array_chunk($pageViews, 200) as $chunk) {
            DB::table('page_views')->insert($chunk);
        }
        foreach (array_chunk($events, 200) as $chunk) {
            DB::table('analytics_events')->insert($chunk);
        }

        $this->command?->info(sprintf(
            'Demo analytics: %d visitors, %d sessions, %d page views, and %d events.',
            count($visitors),
            count($sessions),
            count($pageViews),
            count($events)
        ));
    }

    private function visitorUuid(int $index): string
    {
        return sprintf('30000000-0000-4000-8000-%012d', $index);
    }

    private function sessionUuid(int $index): string
    {
        return sprintf('40000000-0000-4000-8000-%012d', $index);
    }
}
