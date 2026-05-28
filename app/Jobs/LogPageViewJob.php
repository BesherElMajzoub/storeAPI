<?php

namespace App\Jobs;

use App\Models\PageView;
use App\Models\Visitor;
use App\Models\VisitorSession;
use App\Services\GeoLocationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class LogPageViewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected array $payload)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(GeoLocationService $geo): void
    {
        $visitorUuid = $this->payload['visitor_uuid'];
        $sessionUuid = $this->payload['session_uuid'];
        $ip = $this->payload['ip'] ?? '';
        $ipHash = hash('sha256', $ip);

        // 1. Resolve or Create Visitor
        $visitor = Visitor::where('visitor_uuid', $visitorUuid)->first();

        if (!$visitor) {
            $geoData = [
                'country'   => null,
                'city'      => null,
                'region'    => null,
                'latitude'  => null,
                'longitude' => null,
            ];

            if (config('analytics.geoip_enabled', true) && !empty($ip)) {
                $resolved = $geo->resolveIpAndCountry($ip);
                $geoData = [
                    'country'   => $resolved['country_name'] ?? $resolved['country_code'] ?? null,
                    'city'      => $resolved['city'] ?? null,
                    'region'    => $resolved['region'] ?? null,
                    'latitude'  => $resolved['latitude'] ?? null,
                    'longitude' => $resolved['longitude'] ?? null,
                ];
            }

            $visitor = Visitor::create([
                'visitor_uuid'     => $visitorUuid,
                'ip_hash'          => $ipHash,
                'user_id'          => $this->payload['user_id'] ?? null,
                'browser'          => $this->payload['browser'] ?? null,
                'device'           => $this->payload['device'] ?? null,
                'operating_system' => $this->payload['operating_system'] ?? null,
                'user_agent'       => $this->payload['user_agent'] ?? null,
                'country'          => $geoData['country'],
                'city'             => $geoData['city'],
                'region'           => $geoData['region'],
                'latitude'         => $geoData['latitude'],
                'longitude'        => $geoData['longitude'],
            ]);
        } elseif (empty($visitor->user_id) && !empty($this->payload['user_id'])) {
            // Associate user if they just logged in
            $visitor->update(['user_id' => $this->payload['user_id']]);
        }

        // 2. Resolve or Create Visitor Session
        $session = VisitorSession::where('session_uuid', $sessionUuid)->first();

        if (!$session) {
            $session = VisitorSession::create([
                'session_uuid' => $sessionUuid,
                'visitor_uuid' => $visitorUuid,
                'user_id'      => $this->payload['user_id'] ?? null,
                'referrer'     => $this->payload['referrer'] ?? null,
                'utm_source'   => $this->payload['utm_source'] ?? null,
                'utm_medium'   => $this->payload['utm_medium'] ?? null,
                'utm_campaign' => $this->payload['utm_campaign'] ?? null,
                'utm_term'     => $this->payload['utm_term'] ?? null,
                'utm_content'  => $this->payload['utm_content'] ?? null,
                'landing_page' => $this->payload['url'] ?? null,
            ]);
        } elseif (empty($session->user_id) && !empty($this->payload['user_id'])) {
            $session->update(['user_id' => $this->payload['user_id']]);
        }

        // 3. Avoid duplicate page views within a 15-second window
        $duplicate = PageView::where('session_uuid', $sessionUuid)
            ->where('url', $this->payload['url'])
            ->where('visited_at', '>=', Carbon::parse($this->payload['visited_at'])->subSeconds(15))
            ->exists();

        if ($duplicate) {
            return;
        }

        // 4. Create Page View
        PageView::create([
            'session_uuid' => $sessionUuid,
            'visitor_uuid' => $visitorUuid,
            'user_id'      => $this->payload['user_id'] ?? null,
            'url'          => $this->payload['url'],
            'referrer'     => $this->payload['referrer'] ?? null,
            'visited_at'   => $this->payload['visited_at'],
        ]);
    }
}
