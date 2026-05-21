<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                         => $this->id,
            'order_number'               => $this->order_number,
            'status'                     => $this->status,
            'payment_status'             => $this->payment_status,
            'subtotal'                   => (float) $this->subtotal,
            'tax'                        => (float) $this->tax,
            'shipping_cost'              => (float) $this->shipping_cost,
            'discount'                   => (float) $this->discount,
            'total'                      => (float) $this->total,
            'items'                      => OrderItemResource::collection($this->whenLoaded('items')),
            'shipping_address'           => $this->shipping_address,
            'billing_address'            => $this->billing_address,
            'stripe_session_id'          => $this->stripe_session_id,
            'stripe_payment_intent_id'   => $this->stripe_payment_intent_id,
            'paid_at'                    => $this->paid_at?->toIso8601String(),
            'cancelled_at'               => $this->cancelled_at?->toIso8601String(),
            'refunded_at'                => $this->refunded_at?->toIso8601String(),
            'created_at'                 => $this->created_at->toIso8601String(),
            'cancellation_request'       => $this->when(
                $this->relationLoaded('cancellationRequest'),
                fn() => $this->cancellationRequest
                    ? new \App\Http\Resources\CancellationRequestResource($this->cancellationRequest)
                    : null
            ),
        ];
    }
}
