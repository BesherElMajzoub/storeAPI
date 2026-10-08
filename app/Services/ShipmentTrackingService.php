<?php

namespace App\Services;

use App\Models\Order;
use Carbon\Carbon;

class ShipmentTrackingService
{
    public const STATUSES = [
        'unknown', 'pre_transit', 'in_transit', 'out_for_delivery',
        'available_for_pickup', 'delivered', 'return_to_sender',
        'failure', 'cancelled', 'error',
    ];

    public function sync(Order $order, object $tracker): Order
    {
        if ($this->isStale($order, $tracker)) {
            return $order;
        }

        $status = in_array($tracker->status ?? null, self::STATUSES, true)
            ? $tracker->status
            : 'unknown';

        // Carriers report "unknown" until the first scan: the label exists,
        // so that is pre_transit, and it never erases a status we already know.
        if ($status === 'unknown') {
            $status = in_array($order->shipment_status, [null, 'unknown'], true) ? 'pre_transit' : $order->shipment_status;
        }

        $events = $this->events($tracker);

        $order->forceFill([
            'shipment_status' => $status,
            'tracking_url' => $tracker->public_url ?? $order->tracking_url,
            'shipping_carrier' => $tracker->carrier ?? $order->shipping_carrier,
            'estimated_delivery' => isset($tracker->est_delivery_date)
                ? Carbon::parse($tracker->est_delivery_date)->toDateString()
                : $order->estimated_delivery,
            'tracking_events' => $events,
        ]);

        if ($status === 'delivered' && in_array($order->status, ['pending', 'processing', 'shipped'], true)) {
            $order->status = 'delivered';
        } elseif (in_array($status, ['in_transit', 'out_for_delivery', 'available_for_pickup'], true)
            && in_array($order->status, ['pending', 'processing'], true)) {
            $order->status = 'shipped';
        }

        $order->save();

        return $order->refresh();
    }

    /**
     * True when the tracker snapshot is older than the one the order holds.
     * EasyPost webhooks can arrive out of order and each carries the whole
     * history, so an older snapshot must not roll status or timeline back.
     */
    public function isStale(Order $order, object $tracker): bool
    {
        $incoming = $this->latestEventAt($this->events($tracker));
        $stored = $this->latestEventAt($order->tracking_events ?? []);

        return $incoming !== null && $stored !== null && $incoming->lt($stored);
    }

    /** @return list<array{status: string, description: string, location: ?string, occurred_at: ?string}> */
    private function events(object $tracker): array
    {
        return collect($tracker->tracking_details ?? [])->map(function ($detail) {
            $location = $detail->tracking_location ?? null;
            $parts = array_filter([
                $location->city ?? null,
                $location->state ?? null,
                $location->country ?? null,
            ]);

            return [
                'status' => in_array($detail->status ?? null, self::STATUSES, true) ? $detail->status : 'unknown',
                'description' => $detail->message ?? '',
                'location' => $parts ? implode(', ', $parts) : null,
                'occurred_at' => isset($detail->datetime) ? Carbon::parse($detail->datetime)->toIso8601String() : null,
            ];
        })->sortByDesc('occurred_at')->values()->all();
    }

    private function latestEventAt(array $events): ?Carbon
    {
        $times = collect($events)->pluck('occurred_at')->filter();

        return $times->isEmpty() ? null : $times->map(fn ($time) => Carbon::parse($time))->max();
    }
}
