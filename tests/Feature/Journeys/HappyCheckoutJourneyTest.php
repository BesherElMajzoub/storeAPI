<?php

namespace Tests\Feature\Journeys;

use App\Contracts\EasyPostServiceInterface;
use App\Mail\OrderPaidMail;
use App\Models\Product;
use App\Models\User;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

/**
 * J04 - Happy checkout: login -> address -> shipping rates -> create order
 * with a rate -> Stripe session -> signed checkout.session.completed webhook
 * -> order paid -> stock decremented -> paid mail -> order visible in "my
 * orders". Every step after login is a real HTTP call; only Stripe/EasyPost
 * themselves are faked (never a real external call).
 */
class HappyCheckoutJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_go_from_login_to_a_paid_order(): void
    {
        Mail::fake();

        $user = User::factory()->create(['password' => 'correct-password-1']);
        $product = Product::factory()->create([
            'price' => 50.00,
            'stock_qty' => 10,
            'in_stock' => true,
            'status' => 'published',
        ]);

        // Step 1: login.
        $login = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password-1',
        ])->assertOk();
        $token = $login->json('data.access_token');
        $this->assertNotEmpty($token);

        // Step 2: save a shipping address.
        $addressResponse = $this->withToken($token)->postJson('/api/v1/profile/addresses', [
            'label' => 'home',
            'full_name' => 'Jane Buyer',
            'phone' => '+12025550123',
            'country' => 'US',
            'city' => 'Los Angeles',
            'street' => '123 Main Street',
            'postal_code' => '90001',
        ])->assertCreated();
        $address = $addressResponse->json('data');

        // Step 3: real shipping-rates HTTP call (EasyPost itself is faked).
        $rateId = 'rate_'.strtolower(fake()->unique()->bothify('????########'));
        $shipmentId = 'shp_'.strtolower(fake()->unique()->bothify('????########'));
        $easyPostRate = (object) [
            'id' => $rateId,
            'shipment_id' => $shipmentId,
            'carrier' => 'USPS',
            'service' => 'Priority',
            'rate' => '5.50',
            'currency' => 'USD',
            'delivery_days' => 3,
        ];
        $easyPost = $this->mock(EasyPostServiceInterface::class);
        $easyPost->shouldReceive('getShippingRates')->once()->andReturn(
            (object) ['id' => $shipmentId, 'rates' => [$easyPostRate]]
        );
        $easyPost->shouldReceive('retrieveRate')->zeroOrMoreTimes()->andReturn($easyPostRate);

        $ratesResponse = $this->withToken($token)->postJson('/api/v1/shipping/rates', [
            'address' => [
                'line1' => $address['street'],
                'city' => $address['city'],
                'state' => 'CA',
                'postal_code' => '90001',
                'country' => 'US',
            ],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk();

        $quotedRateId = $ratesResponse->json('data.0.rate_id');
        $this->assertSame($rateId, $quotedRateId);

        // Step 4: create the order using the quoted rate (Stripe itself is faked).
        $mockSession = StripeSession::constructFrom([
            'id' => 'cs_journey_test',
            'url' => 'https://checkout.stripe.com/pay/cs_journey_test',
        ]);
        $this->mock(StripeCheckoutService::class, function ($mock) use ($mockSession) {
            $mock->shouldReceive('createCheckoutSession')->once()->andReturn($mockSession);
        });

        $orderResponse = $this->withToken($token)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address' => [
                'name' => 'Jane Buyer',
                'line1' => $address['street'],
                'city' => $address['city'],
                'state' => 'CA',
                'postal_code' => '90001',
                'country' => 'US',
            ],
            'shipping_rate_id' => $quotedRateId,
        ])->assertCreated();

        $orderId = $orderResponse->json('data.order.id');
        $orderResponse->assertJsonPath('data.checkout_url', 'https://checkout.stripe.com/pay/cs_journey_test');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
        ]);
        // Stock is reserved (decremented) at order creation time, before payment.
        $this->assertSame(9, $product->fresh()->stock_qty);

        // Step 5: EasyPost webhook and Stripe webhook are separate systems —
        // the payment confirmation comes from a real, signed Stripe webhook.
        $payload = json_encode([
            'id' => 'evt_journey_test', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_journey_test',
                'payment_intent' => 'pi_journey_test', 'amount_total' => 5550, 'currency' => 'usd',
                'metadata' => ['order_id' => (string) $orderId],
            ]],
        ], JSON_THROW_ON_ERROR);
        $secret = 'whsec_journey_test';
        $timestamp = time();
        $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        config(['services.stripe.webhook_secret' => $secret, 'services.stripe.currency' => 'usd']);

        $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $payload)->assertOk();

        // Step 6: order is now paid, stock unchanged by payment (already
        // reserved at creation), and a receipt mail was queued.
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);
        $this->assertSame(9, $product->fresh()->stock_qty);
        Mail::assertQueued(OrderPaidMail::class, 1);

        // Step 7: the order is visible in "my orders" with the final state.
        $myOrders = $this->withToken($token)->getJson('/api/v1/orders')->assertOk();
        $myOrders->assertJsonFragment(['id' => $orderId, 'status' => 'processing', 'payment_status' => 'paid']);
    }
}
