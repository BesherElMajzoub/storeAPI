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
        $status = in_array($tracker->status ?? null, self::STATUSES, true)
            ? $tracker->status
            : 'unknown';

        $events = collect($tracker->tracking_details ?? [])->map(function ($detail) {
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

        $order->forceFill([
            'shipment_status' => $status,
            'tracking_url' => $tracker->public_url ?? $order->tracking_url,
            'shipping_carrier' => $tracker->carrier ?? $order->shipping_carrier,
            'estimated_delivery' => isset($tracker->est_delivery_date)
                ? Carbon::parse($tracker->est_delivery_date)->toDateString()
                : $order->estimated_delivery,
            'tracking_events' => $events,
        ]);

        if ($status === 'delivered') {
            $order->status = 'delivered';
        } elseif (in_array($status, ['in_transit', 'out_for_delivery', 'available_for_pickup'], true)
            && in_array($order->status, ['pending', 'processing'], true)) {
            $order->status = 'shipped';
        }

        $order->save();

        return $order->refresh();
    }
}
