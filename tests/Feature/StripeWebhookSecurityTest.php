<?php

namespace Tests\Feature;

use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class StripeWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_real_signed_checkout_webhook_marks_the_matching_amount_and_currency_paid(): void
    {
        Mail::fake();

        $order = Order::factory()->for(User::factory())->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_signed',
            'total' => 100,
        ]);

        $response = $this->postSigned([
            'id' => 'evt_signed', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_signed',
                'payment_intent' => 'pi_signed', 'amount_total' => 10000, 'currency' => 'usd',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'processing',
            'payment_status' => 'paid',
            'stripe_payment_intent_id' => 'pi_signed',
        ]);
        Mail::assertQueued(OrderPaidMail::class, 1);

        // Replay is idempotent and does not change the final state.
        $this->postSigned([
            'id' => 'evt_signed', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_signed',
                'payment_intent' => 'pi_signed', 'amount_total' => 10000, 'currency' => 'usd',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ])->assertOk();

        Mail::assertQueued(OrderPaidMail::class, 1);
    }

    public function test_signed_webhook_with_wrong_amount_or_currency_is_rejected(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'pending_payment', 'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_mismatch', 'total' => 100,
        ]);

        $this->postSigned([
            'id' => 'evt_mismatch', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_mismatch',
                'payment_intent' => 'pi_mismatch', 'amount_total' => 100, 'currency' => 'eur',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ])->assertUnprocessable();

        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_signed_expired_session_cancels_only_the_matching_order(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_expired',
        ]);

        $this->postSigned([
            'id' => 'evt_expired', 'object' => 'event', 'type' => 'checkout.session.expired',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_expired',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'payment_status' => 'failed',
        ]);
    }

    public function test_signed_partial_refund_records_amount_without_restocking_or_closing_order(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'processing',
            'payment_status' => 'paid',
            'stripe_payment_intent_id' => 'pi_partial',
            'total' => 100,
            'refunded_amount' => 0,
        ]);

        $this->postSigned([
            'id' => 'evt_partial', 'object' => 'event', 'type' => 'charge.refunded',
            'data' => ['object' => [
                'object' => 'charge', 'payment_intent' => 'pi_partial',
                'amount_refunded' => 2500,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'processing',
            'payment_status' => 'paid',
            'refunded_amount' => 25,
        ]);
        $this->assertNull($order->fresh()->stock_released_at);
    }

    private function postSigned(array $event)
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $secret = 'whsec_readiness_test';
        $timestamp = time();
        $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        config(['services.stripe.webhook_secret' => $secret, 'services.stripe.currency' => 'usd']);

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $payload);
    }
}
