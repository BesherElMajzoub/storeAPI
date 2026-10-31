<?php

namespace App\Services\Address;

use MaxMind\Db\Reader;

/**
 * Approximate US location of an IP address, read from the local DB-IP "IP to City Lite"
 * file. The lookup never leaves the server and the IP is not stored.
 */
class IpLocator
{
    private ?Reader $reader = null;

    private bool $opened = false;

    /** @return array{lat: float, lng: float, state: string|null}|null null when unknown or outside the US */
    public function locate(?string $ip): ?array
    {
        if (! $ip || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return null;
        }

        $reader = $this->reader();
        if (! $reader) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (\Throwable) {
            return null;
        }

        if (! is_array($record) || ($record['country']['iso_code'] ?? null) !== 'US') {
            return null;
        }

        $lat = $record['location']['latitude'] ?? null;
        $lng = $record['location']['longitude'] ?? null;
        if (! is_numeric($lat) || ! is_numeric($lng)) {
            return null;
        }

        return [
            'lat' => (float) $lat,
            'lng' => (float) $lng,
            'state' => UsStates::codeForName($record['subdivisions'][0]['names']['en'] ?? null),
        ];
    }

    public static function databasePath(): string
    {
        return (string) config('services.geo.ip_database');
    }

    private function reader(): ?Reader
    {
        if ($this->opened) {
            return $this->reader;
        }
        $this->opened = true;

        $path = self::databasePath();
        if (! is_file($path)) {
            return null;
        }

        try {
            return $this->reader = new Reader($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
