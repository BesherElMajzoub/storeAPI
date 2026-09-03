<?php

namespace App\Services;

use App\Models\Order;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Coupon;
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
        $currency = config('services.stripe.currency', 'usd');
        $lineItems = $order->items->map(function ($item) use ($currency) {
            return [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) round($item->price * 100), // cents
                    'product_data' => [
                        'name' => $item->product_name.($item->variant_name ? ' – '.$item->variant_name : ''),
                    ],
                ],
                'quantity' => $item->quantity,
            ];
        })->toArray();

        if ($order->shipping_cost > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => (int) round($order->shipping_cost * 100), // cents
                    'product_data' => [
                        'name' => 'Shipping Cost',
                    ],
                ],
                'quantity' => 1,
            ];
        }

        $frontendUrl = rtrim(config('app.frontend_url', config('app.url')), '/');

        $sessionParams = [
            'mode' => 'payment',
            'line_items' => $lineItems,
            'metadata' => [
                'order_id' => (string) $order->id,
                'coupon_code' => (string) $order->coupon_code,
                'discount' => (string) $order->discount,
            ],
            'success_url' => "{$frontendUrl}/orders/{$order->id}?stripe_status=success",
            'cancel_url' => "{$frontendUrl}/checkout?stripe_status=cancelled",
        ];

        if ($order->discount > 0) {
            $stripeCoupon = Coupon::create([
                'amount_off' => (int) round($order->discount * 100),
                'currency' => $currency,
                'duration' => 'once',
                'name' => $order->coupon_code ?: 'Discount',
            ]);
            $sessionParams['discounts'] = [
                ['coupon' => $stripeCoupon->id],
            ];
        }

        return StripeSession::create($sessionParams);
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
