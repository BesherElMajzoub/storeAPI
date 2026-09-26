<?php

namespace Tests\Feature;

use App\Jobs\SendAdminAlert;
use App\Mail\OrderPaidMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Stripe\Refund;
use Tests\TestCase;

class StripeWebhookSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([SendAdminAlert::class]);
    }

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
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'transaction_id' => 'pi_signed',
            'payment_provider' => 'stripe',
            'status' => 'completed',
            'amount' => 100,
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

    public function test_completed_webhook_is_rejected_when_its_session_is_replaced_during_processing(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_original',
            'total' => 100,
        ]);
        $sessionWasReplaced = false;

        Order::retrieved(function (Order $retrievedOrder) use ($order, &$sessionWasReplaced): void {
            if ($sessionWasReplaced || $retrievedOrder->id !== $order->id) {
                return;
            }

            $sessionWasReplaced = true;
            $retrievedOrder->getConnection()->table('orders')
                ->where('id', $order->id)
                ->update(['stripe_session_id' => 'cs_replacement']);
        });

        $this->postSigned([
            'id' => 'evt_replaced_session', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_original',
                'payment_intent' => 'pi_replaced_session', 'amount_total' => 10000, 'currency' => 'usd',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ])->assertUnprocessable();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_replacement',
        ]);
    }

    public function test_signed_expired_session_cancels_only_the_matching_order(): void
    {
        $product = Product::factory()->create(['stock_qty' => 0, 'in_stock' => false]);
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_expired',
            'stock_reserved_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'price' => $product->price,
            'quantity' => 1,
            'total' => $product->price,
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
        $this->assertSame(1, $product->fresh()->stock_qty);
    }

    public function test_an_expired_webhook_for_a_replaced_session_does_not_cancel_the_order(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_replacement',
        ]);

        $this->postSigned([
            'id' => 'evt_stale_expired', 'object' => 'event', 'type' => 'checkout.session.expired',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_previous',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_replacement',
        ]);
    }

    public function test_expired_webhook_does_not_cancel_a_session_replaced_during_processing(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_original_expiry',
        ]);
        $sessionWasReplaced = false;

        Order::retrieved(function (Order $retrievedOrder) use ($order, &$sessionWasReplaced): void {
            if ($sessionWasReplaced || $retrievedOrder->id !== $order->id) {
                return;
            }

            $sessionWasReplaced = true;
            $retrievedOrder->getConnection()->table('orders')
                ->where('id', $order->id)
                ->update(['stripe_session_id' => 'cs_replacement_expiry']);
        });

        $this->postSigned([
            'id' => 'evt_replaced_expiry', 'object' => 'event', 'type' => 'checkout.session.expired',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_original_expiry',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_replacement_expiry',
        ]);
    }

    public function test_signed_payment_for_a_cancelled_order_is_recorded_for_manual_refund(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_cancelled_paid',
            'total' => 100,
        ]);

        $this->postSigned([
            'id' => 'evt_cancelled_paid', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_cancelled_paid',
                'payment_intent' => 'pi_cancelled_paid', 'amount_total' => 10000, 'currency' => 'usd',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'payment_status' => 'unpaid',
            'stripe_payment_intent_id' => 'pi_cancelled_paid',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'transaction_id' => 'pi_cancelled_paid',
            'status' => 'requires_refund',
            'amount' => 100,
        ]);
        Queue::assertPushed(SendAdminAlert::class, 1);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('refundOrder')->once()->with(Mockery::on(
                fn (Order $candidate) => $candidate->stripe_payment_intent_id === 'pi_cancelled_paid'
            ))->andReturn(Refund::constructFrom([
                'id' => 're_manual_refund',
                'status' => 'succeeded',
            ]));
        });

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'refunded',
            'payment_status' => 'refunded',
        ]);
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'refunded',
            'amount' => 100,
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
        Payment::create([
            'order_id' => $order->id,
            'transaction_id' => 'pi_partial',
            'payment_provider' => 'stripe',
            'status' => 'completed',
            'amount' => 100,
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
        $this->assertDatabaseHas('payments', [
            'order_id' => $order->id,
            'status' => 'partially_refunded',
            'amount' => 100,
        ]);
    }

    public function test_completed_webhook_replay_after_full_refund_is_ignored_without_alerting(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'refunded',
            'payment_status' => 'refunded',
            'stripe_session_id' => 'cs_refunded_replay',
            'stripe_payment_intent_id' => 'pi_refunded_replay',
            'total' => 100,
            'refunded_amount' => 100,
        ]);
        Payment::create([
            'order_id' => $order->id,
            'transaction_id' => 'pi_refunded_replay',
            'payment_provider' => 'stripe',
            'status' => 'refunded',
            'amount' => 100,
        ]);

        $this->postSigned([
            'id' => 'evt_refunded_replay', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_refunded_replay',
                'payment_intent' => 'pi_refunded_replay', 'amount_total' => 10000, 'currency' => 'usd',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded', 'amount' => 100]);
        Queue::assertNothingPushed();
    }

    public function test_failed_refund_webhook_keeps_the_paid_order_open_and_alerts_admins(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'processing',
            'payment_status' => 'paid',
            'stripe_payment_intent_id' => 'pi_failed_refund',
            'total' => 100,
        ]);

        $this->postSigned([
            'id' => 'evt_failed_refund', 'object' => 'event', 'type' => 'refund.failed',
            'data' => ['object' => [
                'object' => 'refund', 'id' => 're_failed',
                'payment_intent' => 'pi_failed_refund', 'failure_reason' => 'lost_or_stolen_card',
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);
        Queue::assertPushed(SendAdminAlert::class, 1);
    }

    public function test_out_of_order_partial_refund_webhooks_do_not_reduce_the_recorded_refund_amount(): void
    {
        $order = Order::factory()->for(User::factory())->create([
            'status' => 'processing',
            'payment_status' => 'paid',
            'stripe_payment_intent_id' => 'pi_out_of_order_refund',
            'total' => 100,
            'refunded_amount' => 0,
        ]);

        $this->postSigned([
            'id' => 'evt_refund_newer', 'object' => 'event', 'type' => 'charge.refunded',
            'data' => ['object' => [
                'object' => 'charge', 'payment_intent' => 'pi_out_of_order_refund',
                'amount_refunded' => 5000,
            ]],
        ])->assertOk();

        $this->postSigned([
            'id' => 'evt_refund_older', 'object' => 'event', 'type' => 'charge.refunded',
            'data' => ['object' => [
                'object' => 'charge', 'payment_intent' => 'pi_out_of_order_refund',
                'amount_refunded' => 2500,
            ]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'refunded_amount' => 50,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);
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
