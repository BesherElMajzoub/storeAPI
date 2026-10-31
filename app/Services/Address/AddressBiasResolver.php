<?php

namespace App\Services\Address;

use Illuminate\Support\Facades\DB;

/**
 * Picks the one location address suggestions should favour. The first rule with an answer wins:
 * ZIP centre, then state (the visitor's own IP position when it is inside that state, because
 * a large state's centre is a poor guess), then the IP position, then nothing.
 */
class AddressBiasResolver
{
    public function __construct(private readonly IpLocator $ipLocator) {}

    /** @return array{point: array{lat: float, lng: float}|null, source: string} source is zip, state, ip or none */
    public function resolve(?string $postalCode, ?string $state, ?string $ip): array
    {
        $zip = $this->zipCenter($postalCode);
        if ($zip) {
            return ['point' => $zip, 'source' => 'zip'];
        }

        $ipLocation = $this->ipLocator->locate($ip);
        $stateCode = UsStates::code($state);

        if ($stateCode) {
            if ($ipLocation && $ipLocation['state'] === $stateCode) {
                return ['point' => $this->point($ipLocation), 'source' => 'ip'];
            }

            return ['point' => UsStates::center($stateCode), 'source' => 'state'];
        }

        if ($ipLocation) {
            return ['point' => $this->point($ipLocation), 'source' => 'ip'];
        }

        return ['point' => null, 'source' => 'none'];
    }

    /** @return array{lat: float, lng: float}|null */
    private function zipCenter(?string $postalCode): ?array
    {
        if (! is_string($postalCode) || ! preg_match('/^(\d{5})(-\d{4})?$/', trim($postalCode), $match)) {
            return null;
        }

        $row = DB::table('zip_centroids')->where('zip', $match[1])->first();

        return $row ? ['lat' => (float) $row->lat, 'lng' => (float) $row->lng] : null;
    }

    private function point(array $location): array
    {
        return ['lat' => $location['lat'], 'lng' => $location['lng']];
    }
}
