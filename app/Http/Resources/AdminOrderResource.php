<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

class AdminOrderResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'stripe_session_id' => $this->stripe_session_id,
            'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
            'easypost_shipment_id' => $this->easypost_shipment_id,
            'shipping_rate_id' => $this->shipping_rate_id,
            'label_url' => $this->label_url,
            'shipment' => $this->shipmentPayload(true),
            'user' => $this->whenLoaded('user'),
            'payment' => $this->whenLoaded('payment'),
        ]);
    }
}
