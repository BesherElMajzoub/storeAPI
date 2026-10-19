<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Support\RefundAdvice;
use Illuminate\Http\Request;

/** @mixin Order */
class AdminOrderResource extends OrderResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        $advice = RefundAdvice::forRefundState(
            (string) $this->refund_status,
            $this->refund_failure_code,
            $this->refund_failure_message,
            (int) $this->refund_failures,
        );
        $data['refund'] = array_merge($data['refund'], [
            'retryable' => $advice['retryable'],
            'next_action' => $advice['next_action'],
            'requires_reason' => $advice['requires_reason'],
            'failure_code' => $this->refund_failure_code,
            'failure_message' => $this->refund_failure_message,
            'failed_at' => $this->refund_failed_at?->toIso8601String(),
        ]);

        return array_merge($data, [
            'stripe_session_id' => $this->stripe_session_id,
            'stripe_payment_intent_id' => $this->stripe_payment_intent_id,
            'easypost_shipment_id' => $this->easypost_shipment_id,
            'shipping_rate_id' => $this->shipping_rate_id,
            'carrier_shipping_cost' => $this->carrier_shipping_cost === null ? null : (float) $this->carrier_shipping_cost,
            'label_url' => $this->label_url,
            'fulfillment_hold' => (bool) $this->fulfillment_hold,
            'capture_failed_at' => $this->capture_failed_at?->toIso8601String(),
            'shipment' => $this->shipmentPayload(true),
            'user' => $this->whenLoaded('user'),
            'payment' => $this->whenLoaded('payment'),
        ]);
    }
}
