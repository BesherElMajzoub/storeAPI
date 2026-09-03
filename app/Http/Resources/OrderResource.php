<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'subtotal' => (float) $this->subtotal,
            'tax' => (float) $this->tax,
            'shipping_cost' => (float) $this->shipping_cost,
            'discount' => (float) $this->discount,
            'refunded_amount' => (float) $this->refunded_amount,
            'total' => (float) $this->total,
            'coupon_id' => $this->coupon_id,
            'coupon_code' => $this->coupon_code,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'shipping_address' => $this->shipping_address,
            'billing_address' => $this->billing_address,
            'tracking_number' => $this->tracking_number,
            'shipment' => $this->shipmentPayload(false),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'cancellation_request' => $this->when(
                $this->relationLoaded('cancellationRequest'),
                fn () => $this->cancellationRequest
                    ? new CancellationRequestResource($this->cancellationRequest)
                    : null
            ),
        ];
    }

    protected function shipmentPayload(bool $includeLabel): ?array
    {
        if (! $this->tracking_number) {
            return null;
        }

        $shipment = [
            'tracking_number' => $this->tracking_number,
            'carrier' => $this->shipping_carrier,
            'service' => $this->shipping_service,
            'tracking_url' => $this->tracking_url,
            'shipped_at' => $this->shipped_at?->toIso8601String(),
            'estimated_delivery' => $this->estimated_delivery?->format('Y-m-d'),
            'status' => $this->shipment_status ?? 'unknown',
        ];

        if ($includeLabel) {
            $shipment['label_url'] = $this->label_url;
        }

        return $shipment;
    }
}
