<?php

namespace App\Services;

use App\Models\Order;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Refund;
use Stripe\Stripe;

class StripeCheckoutService
{
    public function __construct()
    {
        Stripe::setApiKey(config('services.stripe.secret'));
    }

    /**
     * Create a Stripe Checkout Session for an order.
     */
    public function createCheckoutSession(Order $order): StripeSession
    {
        $lineItems = $order->items->map(function ($item) {
            return [
                'price_data' => [
                    'currency'     => 'usd',
                    'unit_amount'  => (int) round($item->price * 100), // cents
                    'product_data' => [
                        'name'     => $item->product_name . ($item->variant_name ? ' – ' . $item->variant_name : ''),
                    ],
                ],
                'quantity' => $item->quantity,
            ];
        })->toArray();

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        return StripeSession::create([
            'mode'        => 'payment',
            'line_items'  => $lineItems,
            'metadata'    => ['order_id' => (string) $order->id],
            'success_url' => "{$frontendUrl}/orders/{$order->id}?stripe_status=success",
            'cancel_url'  => "{$frontendUrl}/checkout?stripe_status=cancelled",
        ]);
    }

    /**
     * Refund a paid order via its PaymentIntent.
     */
    public function refundOrder(Order $order): Refund
    {
        return Refund::create([
            'payment_intent' => $order->stripe_payment_intent_id,
        ]);
    }
}
