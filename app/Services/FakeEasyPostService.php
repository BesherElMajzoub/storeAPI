<?php

namespace App\Services;

use App\Contracts\EasyPostServiceInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

class FakeEasyPostService implements EasyPostServiceInterface
{
    private const CACHE_TTL_SECONDS = 86400;

    public function verifyAddress(array $address): array
    {
        return [
            'name' => Str::upper((string) ($address['name'] ?? 'CUSTOMER')),
            'street1' => Str::upper((string) ($address['street1'] ?? '')),
            'street2' => filled($address['street2'] ?? null) ? Str::upper((string) $address['street2']) : null,
            'city' => Str::upper((string) ($address['city'] ?? '')),
            'state' => Str::upper((string) ($address['state'] ?? '')),
            'zip' => Str::upper((string) ($address['zip'] ?? '')),
            'country' => Str::upper((string) ($address['country'] ?? 'US')),
            'phone' => $address['phone'] ?? null,
        ];
    }

    public function getShippingRates(array $toAddress, array $parcel = []): object
    {
        $shipmentId = 'shp_mock_'.Str::lower(Str::random(24));
        $rates = collect(config('services.easypost.fake_rates', []))
            ->values()
            ->map(function (array $configuredRate, int $index) use ($shipmentId): object {
                $rate = (object) [
                    'id' => 'rate_mock_'.substr(hash('sha256', "{$shipmentId}|{$index}"), 0, 24),
                    'shipment_id' => $shipmentId,
                    'carrier' => (string) $configuredRate['carrier'],
                    'service' => (string) $configuredRate['service'],
                    'rate' => number_format((float) $configuredRate['rate'], 2, '.', ''),
                    'currency' => Str::upper((string) ($configuredRate['currency'] ?? 'USD')),
                    'delivery_days' => (int) $configuredRate['delivery_days'],
                    'delivery_date' => null,
                ];

                Cache::put($this->rateKey($rate->id), (array) $rate, self::CACHE_TTL_SECONDS);

                return $rate;
            })
            ->all();

        if ($rates === []) {
            throw new RuntimeException('Mock shipping rates are not configured.');
        }

        $shipment = (object) [
            'id' => $shipmentId,
            'rates' => $rates,
            'tracker' => null,
        ];
        $this->storeShipment($shipment);

        return $shipment;
    }

    public function purchaseLabel(string $shipmentId, string $rateId): object
    {
        $rate = $this->retrieveRate($rateId);
        if ($rate->shipment_id !== $shipmentId) {
            throw new RuntimeException('The mock rate does not belong to this shipment.');
        }

        $shipment = $this->retrieveShipment($shipmentId);
        $trackingCode = 'MOCK'.Str::upper(substr(hash('sha256', $shipmentId), 0, 18));
        $tracker = (object) [
            'id' => 'trk_mock_'.substr(hash('sha256', $trackingCode), 0, 24),
            'tracking_code' => $trackingCode,
            'status' => 'pre_transit',
            'status_detail' => 'label_created',
            'carrier' => $rate->carrier,
            'public_url' => "https://example.test/mock-tracking/{$trackingCode}",
            'est_delivery_date' => now()->addDays(max(1, (int) $rate->delivery_days))->toIso8601String(),
            'tracking_details' => [],
        ];

        $shipment->tracking_code = $trackingCode;
        $shipment->postage_label = (object) [
            'label_url' => "https://example.test/mock-labels/{$shipmentId}.pdf",
        ];
        $shipment->tracker = $tracker;
        $this->storeShipment($shipment);
        Cache::put($this->trackerKey($tracker->id), (array) $tracker, self::CACHE_TTL_SECONDS);

        return $shipment;
    }

    public function retrieveRate(string $rateId): object
    {
        $rate = Cache::get($this->rateKey($rateId));
        if (! is_array($rate)) {
            throw new RuntimeException('Mock shipping rate was not found or expired.');
        }

        return (object) $rate;
    }

    public function retrieveShipment(string $shipmentId): object
    {
        $shipment = Cache::get($this->shipmentKey($shipmentId));
        if (! is_array($shipment)) {
            throw new RuntimeException('Mock shipment was not found or expired.');
        }

        return $this->arrayToObject($shipment);
    }

    public function getTrackingStatus(string $trackerId): object
    {
        $tracker = Cache::get($this->trackerKey($trackerId));
        if (! is_array($tracker)) {
            throw new RuntimeException('Mock tracker was not found or expired.');
        }

        return $this->arrayToObject($tracker);
    }

    public function validateWebhook(string $payload, array $headers): object
    {
        $secret = (string) config('services.easypost.webhook_secret');
        if ($secret === '') {
            throw new RuntimeException('Mock shipping webhook is not configured.');
        }

        $normalizedHeaders = collect($headers)
            ->mapWithKeys(fn ($value, $key) => [Str::lower((string) $key) => is_array($value) ? reset($value) : $value]);
        $provided = (string) $normalizedHeaders->get('x-mock-signature', '');
        $expected = hash_hmac('sha256', $payload, $secret);

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            throw new RuntimeException('Invalid mock shipping webhook signature.');
        }

        $event = json_decode($payload);
        if (! is_object($event)) {
            throw new RuntimeException('Invalid mock shipping webhook payload.');
        }

        return $event;
    }

    private function storeShipment(object $shipment): void
    {
        Cache::put(
            $this->shipmentKey($shipment->id),
            json_decode(json_encode($shipment, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR),
            self::CACHE_TTL_SECONDS
        );
    }

    private function arrayToObject(array $value): object
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
    }

    private function shipmentKey(string $shipmentId): string
    {
        return "shipping:fake:shipment:{$shipmentId}";
    }

    private function rateKey(string $rateId): string
    {
        return "shipping:fake:rate:{$rateId}";
    }

    private function trackerKey(string $trackerId): string
    {
        return "shipping:fake:tracker:{$trackerId}";
    }
}
