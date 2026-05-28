<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoLocationService
{
    /**
     * Resolve geo-location data for the given request IP.
     *
     * Resolution order:
     *  1. Cloudflare `CF-IPCountry` header (free, zero latency).
     *  2. ipapi.co free JSON API (fallback – no key required).
     *
     * @return array{country_code:string|null, country_name:string|null, region:string|null, city:string|null}
     */
    public function resolve(Request $request): array
    {
        return $this->resolveIpAndCountry(
            $request->ip(),
            $request->header('CF-IPCountry')
        );
    }

    /**
     * Resolve geo-location data for a given IP and optional Cloudflare country header.
     * Useful when running inside a queue context without request instance.
     */
    public function resolveIpAndCountry(string $ip, ?string $cfCountry = null): array
    {
        // 1️⃣ Cloudflare header – cheapest, no extra HTTP round-trip
        if ($cfCountry && $cfCountry !== 'XX') {          // 'XX' = unknown / Tor
            return [
                'country_code' => strtoupper($cfCountry),
                'country_name' => null,
                'region'       => null,
                'city'         => null,
                'latitude'     => null,
                'longitude'    => null,
                'source'       => 'cloudflare',
            ];
        }

        // Avoid calling the external API for local/private IPs
        if ($this->isPrivateIp($ip)) {
            return $this->emptyResult();
        }

        // 3️⃣ Primary External Geolocation API: ip-api.com (free, fast, up to 45 req/min)
        try {
            $response = Http::timeout(3)
                ->get("http://ip-api.com/json/{$ip}");

            if ($response->successful()) {
                $data = $response->json();

                if (($data['status'] ?? '') === 'success') {
                    return [
                        'country_code' => $data['countryCode'] ?? null,
                        'country_name' => $data['country'] ?? null,
                        'region'       => $data['regionName'] ?? null,
                        'city'         => $data['city'] ?? null,
                        'latitude'     => $data['lat'] ?? null,
                        'longitude'    => $data['lon'] ?? null,
                        'source'       => 'ip-api.com',
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GeoLocationService: ip-api.com request failed', ['error' => $e->getMessage()]);
        }

        // 4️⃣ Secondary Fallback Geolocation API: ipapi.co (free, up to 1000 req/day)
        try {
            $response = Http::timeout(3)
                ->get("https://ipapi.co/{$ip}/json/");

            if ($response->successful()) {
                $data = $response->json();

                // ipapi.co returns {"error": true, "reason": "..."} on bad IPs
                if (empty($data['error'])) {
                    return [
                        'country_code' => $data['country_code'] ?? null,
                        'country_name' => $data['country_name'] ?? null,
                        'region'       => $data['region']       ?? null,
                        'city'         => $data['city']         ?? null,
                        'latitude'     => $data['latitude']     ?? null,
                        'longitude'    => $data['longitude']    ?? null,
                        'source'       => 'ipapi.co',
                    ];
                } else {
                    Log::warning('GeoLocationService: ipapi.co error', ['ip' => $ip, 'reason' => $data['reason'] ?? null]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GeoLocationService: ipapi.co request failed', ['error' => $e->getMessage()]);
        }

        return $this->emptyResult();
    }

    // -------------------------------------------------------------------------

    private function emptyResult(): array
    {
        return [
            'country_code' => null,
            'country_name' => null,
            'region'       => null,
            'city'         => null,
            'latitude'     => null,
            'longitude'    => null,
            'source'       => null,
        ];
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
