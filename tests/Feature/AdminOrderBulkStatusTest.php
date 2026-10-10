<?php

namespace Tests\Feature;

use App\Mail\OrderShippedMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Stripe\Checkout\Session as StripeSession;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Tests\TestCase;

class AdminOrderBulkStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::create(['name' => 'Admin']));
        $this->actingAs($this->admin, 'sanctum');
        Mail::fake();
    }

    public function test_bulk_order_status_update_is_atomic_and_returns_a_result_per_id(): void
    {
        $pending = Order::factory()->create(['status' => 'pending']);
        $processing = Order::factory()->create(['status' => 'processing']);

        $response = $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$pending->id, $processing->id],
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$pending->id, $processing->id],
            collect($response->json('data'))->pluck('id')->all()
        );
        $response->assertJsonPath('data.0.status', 'updated');
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('cancelled', $processing->fresh()->status);
    }

    public function test_one_invalid_transition_rejects_the_entire_bulk_order_update(): void
    {
        $processing = Order::factory()->create(['status' => 'processing']);
        $pendingPayment = Order::factory()->create(['status' => 'pending_payment']);

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$processing->id, $pendingPayment->id],
            'status' => 'shipped',
        ])->assertConflict()
            ->assertJsonPath('errors.orders.'.$pendingPayment->id.'.0', 'Cannot transition order from pending_payment to shipped.');

        $this->assertSame('processing', $processing->fresh()->status);
        $this->assertSame('pending_payment', $pendingPayment->fresh()->status);
    }

    public function test_bulk_order_status_validation_rejects_duplicates_and_soft_deleted_orders(): void
    {
        $order = Order::factory()->create();

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$order->id, $order->id],
            'status' => 'cancelled',
        ])->assertUnprocessable()->assertJsonValidationErrors('ids.1');

        $order->delete();

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$order->id],
            'status' => 'cancelled',
        ])->assertUnprocessable()->assertJsonValidationErrors('ids.0');
    }

    public function test_bulk_cancellation_expires_every_open_checkout_session_before_updating_orders(): void
    {
        $first = Order::factory()->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_bulk_first',
        ]);
        $second = Order::factory()->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_bulk_second',
        ]);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            foreach (['cs_bulk_first', 'cs_bulk_second'] as $sessionId) {
                $mock->shouldReceive('retrieveCheckoutSession')->once()->with($sessionId)
                    ->andReturn(StripeSession::constructFrom(['id' => $sessionId, 'status' => 'open']));
                $mock->shouldReceive('expireCheckoutSession')->once()->with($sessionId)
                    ->andReturn(StripeSession::constructFrom(['id' => $sessionId, 'status' => 'expired']));
            }
        });

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$first->id, $second->id],
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
    }

    public function test_bulk_cancellation_does_not_expire_a_session_when_another_transition_is_invalid(): void
    {
        $pendingPayment = Order::factory()->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_must_not_expire',
        ]);
        $delivered = Order::factory()->create(['status' => 'delivered']);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldNotReceive('retrieveCheckoutSession');
            $mock->shouldNotReceive('expireCheckoutSession');
        });

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$pendingPayment->id, $delivered->id],
            'status' => 'cancelled',
        ])->assertConflict();

        $this->assertSame('pending_payment', $pendingPayment->fresh()->status);
    }

    public function test_single_status_endpoint_marks_shipped_at_and_refund_does_not_restock(): void
    {
        $product = Product::factory()->create(['stock_qty' => 10, 'in_stock' => true]);
        $order = Order::factory()->create([
            'status' => 'processing', 'payment_status' => 'paid',
            'stripe_payment_intent_id' => 'pi_manual_ship', 'stock_reserved_at' => now(), 'total' => 100,
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'price' => 100, 'quantity' => 1, 'total' => 100,
        ]);
        $product->update(['stock_qty' => 9]);
        Payment::create(['order_id' => $order->id, 'payment_provider' => 'stripe', 'status' => 'completed', 'amount' => 100]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'shipped', 'shipping_carrier' => 'USPS', 'tracking_number' => '9400100000000000000001',
        ])->assertOk()->assertJsonPath('data.shipment.tracking_number', '9400100000000000000001');
        $this->assertNotNull($order->fresh()->shipped_at);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('refundOrder')->once()->andReturn(Refund::constructFrom(['id' => 're_manual_ship', 'status' => 'succeeded']));
        });
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertOk();
        $this->assertSame(9, (int) $product->fresh()->stock_qty);
    }

    public function test_bulk_status_endpoint_marks_shipped_at_and_refunds_do_not_restock(): void
    {
        $product = Product::factory()->create(['stock_qty' => 10, 'in_stock' => true]);
        $orders = collect([1, 2])->map(function (int $index) use ($product): Order {
            $order = Order::factory()->create([
                'status' => 'processing', 'payment_status' => 'paid',
                'stripe_payment_intent_id' => "pi_bulk_ship_{$index}", 'stock_reserved_at' => now(), 'total' => 50,
                'tracking_number' => "EZBULK{$index}", 'shipping_carrier' => 'USPS',
            ]);
            $order->items()->create([
                'product_id' => $product->id, 'product_name' => $product->name,
                'price' => 50, 'quantity' => 1, 'total' => 50,
            ]);
            Payment::create(['order_id' => $order->id, 'payment_provider' => 'stripe', 'status' => 'completed', 'amount' => 50]);

            return $order;
        });
        $product->update(['stock_qty' => 8]);

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => $orders->pluck('id')->all(), 'status' => 'shipped',
        ])->assertOk();
        $this->assertTrue($orders->every(fn (Order $order): bool => $order->fresh()->shipped_at !== null));

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('refundOrder')->twice()->andReturn(
                Refund::constructFrom(['id' => 're_bulk_one', 'status' => 'succeeded']),
                Refund::constructFrom(['id' => 're_bulk_two', 'status' => 'succeeded'])
            );
        });
        foreach ($orders as $order) {
            $this->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertOk();
        }
        $this->assertSame(8, (int) $product->fresh()->stock_qty);
    }

    public function test_an_order_cannot_be_marked_shipped_without_tracking(): void
    {
        $order = Order::factory()->create(['status' => 'processing', 'payment_status' => 'paid', 'tracking_number' => null]);

        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Buy a shipping label, or enter the carrier and tracking number, before marking the order shipped.');
        $this->postJson('/api/v1/admin/orders/bulk-status', ['ids' => [$order->id], 'status' => 'shipped'])
            ->assertConflict();

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_manual_shipping_captures_a_held_payment_first(): void
    {
        $order = Order::factory()->create([
            'status' => 'processing', 'payment_status' => 'authorized',
            'stripe_payment_intent_id' => 'pi_manual', 'authorized_at' => now()->subHour(),
        ]);
        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn(PaymentIntent::constructFrom(['id' => 'pi_manual', 'status' => 'requires_capture']));
            $mock->shouldReceive('capturePayment')->once()->andReturn(PaymentIntent::constructFrom(['id' => 'pi_manual', 'status' => 'succeeded']));
        });

        $this->postJson("/api/v1/admin/orders/{$order->id}/status", [
            'status' => 'shipped', 'shipping_carrier' => 'USPS', 'tracking_number' => 'MANUAL1',
        ])->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'shipped', 'payment_status' => 'paid']);
        Mail::assertQueued(OrderShippedMail::class, fn (OrderShippedMail $mail): bool => $mail->order->is($order));
    }

    public function test_bulk_cannot_cancel_orders_that_hold_money(): void
    {
        $paid = Order::factory()->create(['status' => 'processing', 'payment_status' => 'paid']);
        $authorized = Order::factory()->create(['status' => 'processing', 'payment_status' => 'authorized']);

        $response = $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$paid->id, $authorized->id], 'status' => 'cancelled',
        ])->assertConflict();

        $this->assertSame(['Paid orders must be cancelled one at a time from the order page.'], $response->json("errors.orders.{$paid->id}"));
        $this->assertSame('processing', $paid->fresh()->status);
        $this->assertSame('processing', $authorized->fresh()->status);
    }

    public function test_single_status_change_returns_the_admin_order_resource(): void
    {
        $order = Order::factory()->create(['status' => 'pending', 'payment_status' => 'unpaid']);

        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment_status', 'voided')
            ->assertJsonStructure(['data' => ['refund' => ['status', 'amount'], 'cancellation' => ['mode', 'reason'], 'shipment']]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
