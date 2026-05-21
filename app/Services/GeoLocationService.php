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
        // 1️⃣ Cloudflare header – cheapest, no extra HTTP round-trip
        $cfCountry = $request->header('CF-IPCountry');

        if ($cfCountry && $cfCountry !== 'XX') {          // 'XX' = unknown / Tor
            return [
                'country_code' => strtoupper($cfCountry),
                'country_name' => null,
                'region'       => null,
                'city'         => null,
                'source'       => 'cloudflare',
            ];
        }

        // 2️⃣ Fallback: ipapi.co (free tier – 1 000 req/day, no key needed)
        $ip = $request->ip();

        // Avoid calling the external API for local/private IPs
        if ($this->isPrivateIp($ip)) {
            return $this->emptyResult();
        }

        try {
            $response = Http::timeout(3)
                ->get("https://ipapi.co/{$ip}/json/");

            if ($response->successful()) {
                $data = $response->json();

                // ipapi.co returns {"error": true, "reason": "..."} on bad IPs
                if (!empty($data['error'])) {
                    Log::warning('GeoLocationService: ipapi.co error', ['ip' => $ip, 'reason' => $data['reason'] ?? null]);
                    return $this->emptyResult();
                }

                return [
                    'country_code' => $data['country_code'] ?? null,
                    'country_name' => $data['country_name'] ?? null,
                    'region'       => $data['region']       ?? null,
                    'city'         => $data['city']         ?? null,
                    'source'       => 'ipapi.co',
                ];
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
