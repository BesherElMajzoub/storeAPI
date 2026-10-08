<?php

namespace Tests\Feature;

use App\Contracts\EasyPostServiceInterface;
use App\Jobs\SendAdminAlert;
use App\Jobs\SettleCancelledOrderPayment;
use App\Mail\OrderPaidMail;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Stripe\Checkout\Session as StripeSession;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Tests\TestCase;

class OrderPaymentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake([SendAdminAlert::class]);
        $this->customer = User::factory()->create();
    }

    // ── Checkout & webhook ───────────────────────────────────────────────────

    public function test_checkout_session_holds_the_card_instead_of_charging_it(): void
    {
        $stripe = new class extends StripeCheckoutService
        {
            public array $params = [];

            protected function createStripeCheckoutSession(array $sessionParams): StripeSession
            {
                $this->params = $sessionParams;

                return StripeSession::constructFrom(['id' => 'cs_hold', 'url' => 'https://checkout.stripe.test']);
            }
        };
        $order = $this->authorizedOrder(['status' => 'pending_payment', 'payment_status' => 'unpaid']);

        $stripe->createCheckoutSession($order->load('items'));

        $this->assertSame('manual', $stripe->params['payment_intent_data']['capture_method']);
        $this->assertSame('manual', $stripe->params['metadata']['capture_method']);
    }

    public function test_manual_capture_webhook_marks_the_order_authorized_not_paid(): void
    {
        $order = Order::factory()->for($this->customer)->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_hold',
            'total' => 100,
        ]);

        $this->postSigned([
            'id' => 'evt_hold', 'object' => 'event', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'object' => 'checkout.session', 'id' => 'cs_hold',
                'payment_intent' => 'pi_hold', 'amount_total' => 10000, 'currency' => 'usd',
                'metadata' => ['order_id' => (string) $order->id, 'capture_method' => 'manual'],
            ]],
        ])->assertOk();

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame('authorized', $order->payment_status);
        $this->assertNotNull($order->authorized_at);
        $this->assertNull($order->paid_at);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'pending']);
        Mail::assertQueued(OrderPaidMail::class, 1);

        $this->actingAs($this->customer, 'sanctum')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.cancellation.mode', 'direct')
            ->assertJsonPath('data.cancellation.reason', 'within_window')
            ->assertJsonPath('data.cancellation.direct_until', $order->authorized_at->copy()->addHours(3)->toIso8601String())
            ->assertJsonPath('data.refund.status', 'none');
    }

    public function test_cancellation_options_tell_the_frontend_what_is_possible_and_why(): void
    {
        $expired = $this->authorizedOrder(['authorized_at' => now()->subHours(4)]);
        $this->assertSame(['request', 'window_expired'], $this->cancellationOf($expired));

        $claimed = $this->authorizedOrder(['fulfillment_started_at' => now()]);
        $this->assertSame(['request', 'fulfillment_in_progress'], $this->cancellationOf($claimed));

        $labelled = $this->authorizedOrder(['payment_status' => 'paid', 'tracking_number' => 'EZ1']);
        $this->assertSame(['request', 'label_purchased'], $this->cancellationOf($labelled));

        $requested = $this->authorizedOrder(['authorized_at' => now()->subHours(4)]);
        $this->cancellationRequest($requested);
        $response = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$requested->id}")->assertOk();
        $response->assertJsonPath('data.cancellation.mode', 'none')
            ->assertJsonPath('data.cancellation.reason', 'request_pending')
            ->assertJsonPath('data.cancellation.pending_request.status', 'pending');

        $shipped = $this->authorizedOrder(['status' => 'shipped', 'payment_status' => 'paid', 'tracking_number' => 'EZ2']);
        $this->assertSame(['none', 'shipped'], $this->cancellationOf($shipped));
    }

    // ── Customer direct cancel ───────────────────────────────────────────────

    public function test_cancelling_an_authorized_order_releases_the_hold_without_a_refund(): void
    {
        $product = Product::factory()->create(['stock_qty' => 10, 'in_stock' => true]);
        $order = $this->authorizedOrder([], $product);
        $coupon = Coupon::create([
            'code' => 'SAVE10', 'type' => 'fixed', 'value' => 10, 'usage_limit' => 5,
            'used_count' => 1, 'usage_limit_per_user' => 1, 'is_active' => true,
        ]);
        $order->update(['coupon_id' => $coupon->id]);
        CouponUsage::create([
            'coupon_id' => $coupon->id, 'user_id' => $this->customer->id,
            'order_id' => $order->id, 'discount_amount' => 10,
        ]);
        $this->assertSame(8, $product->fresh()->stock_qty);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn($this->intent('requires_capture'));
            $mock->shouldReceive('releaseAuthorization')->once()->andReturn($this->intent('canceled'));
            $mock->shouldNotReceive('capturePayment');
            $mock->shouldNotReceive('refundOrder');
        });

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.order.status', 'cancelled');

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('released', $order->refund_status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(10, $product->fresh()->stock_qty);
        // Nothing was charged, so the coupon goes back too.
        $this->assertSame(0, $coupon->fresh()->used_count);
    }

    public function test_cancelling_a_captured_order_within_the_window_refunds_it_and_keeps_it_cancelled(): void
    {
        $order = $this->authorizedOrder(['payment_status' => 'paid', 'paid_at' => now()]);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->once()->andReturn(Refund::constructFrom(['id' => 're_1', 'status' => 'succeeded']));
            $mock->shouldNotReceive('releaseAuthorization');
        });

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'payment_status' => 'refunded',
            'refund_status' => 'succeeded',
            'refunded_amount' => 100,
        ]);
    }

    public function test_customer_cannot_cancel_an_authorized_order_after_the_window(): void
    {
        $order = $this->authorizedOrder(['authorized_at' => now()->subHours(3)->subMinute()]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('message', 'The 3-hour direct cancellation window has passed. Please submit a cancellation request instead.');

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertFalse($order->fresh()->canBeCancelledByCustomer());
    }

    public function test_customer_cannot_cancel_once_a_shipping_label_exists(): void
    {
        $order = $this->authorizedOrder(['payment_status' => 'paid', 'tracking_number' => 'EZ1']);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('message', 'This order can no longer be cancelled directly.')
            ->assertJsonPath('errors.reason.0', 'label_purchased');
    }

    public function test_customer_cannot_cancel_while_a_label_purchase_is_in_progress(): void
    {
        // The admin's label purchase committed its claim before calling EasyPost.
        $order = $this->authorizedOrder(['fulfillment_started_at' => now()]);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(400)
            ->assertJsonPath('errors.reason.0', 'fulfillment_in_progress');

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_cancelling_a_captured_order_gives_the_coupon_back(): void
    {
        $order = $this->authorizedOrder(['payment_status' => 'paid', 'paid_at' => now()->subDay(), 'authorized_at' => now()->subDay()]);
        $coupon = Coupon::create([
            'code' => 'WELCOME10', 'type' => 'fixed', 'value' => 10, 'usage_limit' => 5,
            'used_count' => 1, 'usage_limit_per_user' => 1, 'is_active' => true,
        ]);
        $order->update(['coupon_id' => $coupon->id]);
        CouponUsage::create([
            'coupon_id' => $coupon->id, 'user_id' => $this->customer->id,
            'order_id' => $order->id, 'discount_amount' => 10,
        ]);
        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->andReturn(Refund::constructFrom(['id' => 're_c', 'status' => 'succeeded']));
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/v1/admin/cancellation-requests/'.$this->cancellationRequest($order)->id.'/accept')
            ->assertOk();

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertSame(0, CouponUsage::where('order_id', $order->id)->count());
    }

    public function test_a_stripe_outage_does_not_undo_the_cancellation_and_ends_in_failed_after_retries(): void
    {
        Queue::fake([SendAdminAlert::class, SettleCancelledOrderPayment::class]);
        $product = Product::factory()->create(['stock_qty' => 10, 'in_stock' => true]);
        $order = $this->authorizedOrder([], $product);

        $this->actingAs($this->customer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.order.refund.status', 'pending')
            ->assertJsonPath('data.order.refund.amount', 100);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock_qty);
        Queue::assertPushed(SettleCancelledOrderPayment::class, fn ($job) => $job->orderId === $order->id);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->andThrow(new RuntimeException('Stripe is down'));
        });
        $job = new SettleCancelledOrderPayment($order->id);
        try {
            $job->handle(app(OrderPaymentService::class));
            $this->fail('Expected the job to throw so the queue retries it.');
        } catch (RuntimeException) {
        }
        $this->assertSame('pending', $order->fresh()->refund_status);

        $job->failed(new RuntimeException('Stripe is down'));

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->refund_status);
        Queue::assertPushed(SendAdminAlert::class, 1);
    }

    // ── Settlement race with capture ─────────────────────────────────────────

    public function test_settlement_refunds_when_the_capture_won_the_race(): void
    {
        // The order still says authorized locally, but Stripe already captured it.
        $order = $this->authorizedOrder();
        $order->forceFill(['status' => 'cancelled', 'refund_status' => 'pending'])->saveQuietly();

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->once()->andReturn(Refund::constructFrom(['id' => 're_race', 'status' => 'succeeded']));
            $mock->shouldNotReceive('releaseAuthorization');
        });

        $this->assertSame('succeeded', app(OrderPaymentService::class)->settleCancellation($order));
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_capture_does_nothing_for_an_order_cancelled_meanwhile(): void
    {
        $order = $this->authorizedOrder();
        $order->forceFill(['status' => 'cancelled', 'refund_status' => 'pending'])->saveQuietly();

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldNotReceive('retrievePaymentIntent');
            $mock->shouldNotReceive('capturePayment');
        });

        $this->assertFalse(app(OrderPaymentService::class)->capture($order));
        $this->assertSame('authorized', $order->fresh()->payment_status);
    }

    // ── Admin cancellation request ───────────────────────────────────────────

    public function test_accepting_a_cancellation_request_for_a_captured_order_refunds_it(): void
    {
        $order = $this->authorizedOrder(['payment_status' => 'paid', 'paid_at' => now()->subDay(), 'authorized_at' => now()->subDay()]);
        $request = $this->cancellationRequest($order);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->once()->andReturn(Refund::constructFrom(['id' => 're_req', 'status' => 'succeeded']));
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/cancellation-requests/{$request->id}/accept")
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'payment_status' => 'refunded',
            'refund_status' => 'succeeded',
        ]);
        $this->assertSame('accepted', $request->fresh()->status);
    }

    public function test_a_cancellation_request_cannot_be_accepted_after_the_order_shipped(): void
    {
        $order = $this->authorizedOrder(['status' => 'shipped', 'payment_status' => 'paid', 'tracking_number' => 'EZ1']);
        $request = $this->cancellationRequest($order);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/cancellation-requests/{$request->id}/accept")
            ->assertStatus(409);

        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('pending', $request->fresh()->status);
    }

    // ── Capture ──────────────────────────────────────────────────────────────

    public function test_scheduler_captures_only_holds_whose_cancel_window_has_closed(): void
    {
        $due = $this->authorizedOrder(['authorized_at' => now()->subHours(3)->subMinute(), 'stripe_payment_intent_id' => 'pi_due']);
        $fresh = $this->authorizedOrder(['authorized_at' => now()->subHour(), 'stripe_payment_intent_id' => 'pi_fresh']);
        Payment::create(['order_id' => $due->id, 'transaction_id' => 'pi_due', 'payment_provider' => 'stripe', 'status' => 'pending', 'amount' => 100]);

        $this->mock(StripeCheckoutService::class, function ($mock) use ($due): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()
                ->withArgs(fn (Order $order) => $order->id === $due->id)
                ->andReturn($this->intent('requires_capture'));
            $mock->shouldReceive('capturePayment')->once()->andReturn($this->intent('succeeded'));
        });

        $this->artisan('orders:capture-authorized-payments')->assertSuccessful();

        $this->assertSame('paid', $due->fresh()->payment_status);
        $this->assertNotNull($due->fresh()->paid_at);
        $this->assertDatabaseHas('payments', ['order_id' => $due->id, 'status' => 'completed']);
        $this->assertSame('authorized', $fresh->fresh()->payment_status);
    }

    public function test_an_expired_hold_is_flagged_instead_of_captured(): void
    {
        $order = $this->authorizedOrder(['authorized_at' => now()->subDays(8)]);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn($this->intent('canceled'));
            $mock->shouldNotReceive('capturePayment');
        });

        $this->artisan('orders:capture-authorized-payments')->assertSuccessful();

        $this->assertSame('failed', $order->fresh()->payment_status);
        Queue::assertPushed(SendAdminAlert::class, 1);
    }

    public function test_buying_a_label_captures_the_held_payment_first(): void
    {
        $order = $this->authorizedOrder(['easypost_shipment_id' => 'shp_1', 'shipping_rate_id' => 'rate_1']);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn($this->intent('requires_capture'));
            $mock->shouldReceive('capturePayment')->once()->andReturn($this->intent('succeeded'));
        });
        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('purchaseLabel')->once()->andReturn((object) [
                'tracking_code' => 'EZ100',
                'postage_label' => (object) ['label_url' => 'https://easypost.test/label.png'],
            ]);
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/ship")
            ->assertOk();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'shipped', 'payment_status' => 'paid']);
    }

    public function test_no_label_is_bought_when_the_capture_fails(): void
    {
        $order = $this->authorizedOrder(['easypost_shipment_id' => 'shp_1', 'shipping_rate_id' => 'rate_1']);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andThrow(new RuntimeException('Stripe is down'));
        });
        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldNotReceive('purchaseLabel');
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/ship")
            ->assertStatus(502);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'processing', 'payment_status' => 'authorized']);
        // The claim is cleared, so the customer can still cancel.
        $this->assertNull($order->fresh()->fulfillment_started_at);
        $this->assertTrue($order->fresh()->canBeCancelledByCustomer());
    }

    public function test_a_pending_cancellation_request_puts_the_order_on_fulfilment_hold(): void
    {
        $order = $this->authorizedOrder(['payment_status' => 'paid', 'easypost_shipment_id' => 'shp_1', 'shipping_rate_id' => 'rate_1']);
        $this->cancellationRequest($order);

        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldNotReceive('purchaseLabel');
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/ship")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This order has a pending cancellation request. Accept or reject it before shipping.');

        $this->assertNull($order->fresh()->fulfillment_started_at);
    }

    public function test_a_second_label_purchase_is_refused_while_one_is_in_progress(): void
    {
        $order = $this->authorizedOrder([
            'payment_status' => 'paid', 'easypost_shipment_id' => 'shp_1', 'shipping_rate_id' => 'rate_1',
            'fulfillment_started_at' => now()->subMinute(),
        ]);
        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldNotReceive('purchaseLabel');
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/ship")
            ->assertStatus(409);
    }

    public function test_scheduler_keeps_the_hold_while_a_cancellation_request_awaits_the_admin(): void
    {
        $requested = $this->authorizedOrder(['authorized_at' => now()->subHours(4), 'stripe_payment_intent_id' => 'pi_requested']);
        $this->cancellationRequest($requested);
        $nearExpiry = $this->authorizedOrder(['authorized_at' => now()->subDays(5)->subMinute(), 'stripe_payment_intent_id' => 'pi_old']);
        $this->cancellationRequest($nearExpiry);

        $this->mock(StripeCheckoutService::class, function ($mock) use ($nearExpiry): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()
                ->withArgs(fn (Order $order) => $order->id === $nearExpiry->id)
                ->andReturn($this->intent('requires_capture'));
            $mock->shouldReceive('capturePayment')->once()->andReturn($this->intent('succeeded'));
        });

        $this->artisan('orders:capture-authorized-payments')->assertSuccessful();

        $this->assertSame('authorized', $requested->fresh()->payment_status);
        $this->assertSame('paid', $nearExpiry->fresh()->payment_status);
    }

    public function test_accepting_a_request_is_refused_while_the_label_is_being_bought(): void
    {
        $order = $this->authorizedOrder(['payment_status' => 'paid', 'fulfillment_started_at' => now()]);
        $request = $this->cancellationRequest($order);

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/cancellation-requests/{$request->id}/accept")
            ->assertStatus(409);

        $this->assertSame('processing', $order->fresh()->status);
    }

    // ── Refund webhooks & admin retry ────────────────────────────────────────

    public function test_refund_webhooks_update_refund_status_without_reopening_a_cancelled_order(): void
    {
        $order = $this->authorizedOrder(['payment_status' => 'paid']);
        $order->forceFill(['status' => 'cancelled', 'refund_status' => 'pending'])->saveQuietly();

        $this->postSigned([
            'id' => 'evt_refunded', 'object' => 'event', 'type' => 'charge.refunded',
            'data' => ['object' => ['object' => 'charge', 'payment_intent' => 'pi_auth', 'amount_refunded' => 10000]],
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
            'payment_status' => 'refunded',
            'refund_status' => 'succeeded',
        ]);

        $this->postSigned([
            'id' => 'evt_refund_failed', 'object' => 'event', 'type' => 'refund.failed',
            'data' => ['object' => ['object' => 'refund', 'id' => 're_x', 'payment_intent' => 'pi_auth']],
        ])->assertOk();

        $this->assertSame('failed', $order->fresh()->refund_status);
    }

    public function test_admin_can_retry_a_failed_settlement(): void
    {
        $order = $this->authorizedOrder(['payment_status' => 'paid']);
        $order->forceFill(['status' => 'cancelled', 'refund_status' => 'failed'])->saveQuietly();

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->once()->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->once()->andReturn(Refund::constructFrom(['id' => 're_retry', 'status' => 'succeeded']));
        });

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertOk()
            ->assertJsonPath('data.refund_status', 'succeeded');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled', 'refund_status' => 'succeeded']);
    }

    public function test_admin_cannot_refund_a_hold_that_was_never_captured(): void
    {
        $order = $this->authorizedOrder();

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertStatus(409);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function authorizedOrder(array $attributes = [], ?Product $product = null): Order
    {
        $product ??= Product::factory()->create(['stock_qty' => 10, 'in_stock' => true]);
        $order = Order::factory()->for($this->customer)->create(['status' => 'pending_payment', 'total' => 100]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'price' => 50, 'quantity' => 2, 'total' => 100,
        ]);
        // Moving to processing reserves stock through OrderObserver, as checkout does.
        $order->update(array_merge([
            'status' => 'processing',
            'payment_status' => 'authorized',
            'stripe_payment_intent_id' => 'pi_auth',
            'authorized_at' => now()->subMinutes(30),
        ], $attributes));

        return $order->fresh();
    }

    /** @return array{0: string, 1: string} [mode, reason] as the storefront sees them */
    private function cancellationOf(Order $order): array
    {
        $response = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk();

        return [$response->json('data.cancellation.mode'), $response->json('data.cancellation.reason')];
    }

    private function cancellationRequest(Order $order): OrderCancellationRequest
    {
        return OrderCancellationRequest::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'reason' => 'Changed my mind about it.',
            'status' => 'pending',
        ]);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));

        return $admin;
    }

    private function intent(string $status): PaymentIntent
    {
        return PaymentIntent::constructFrom(['id' => 'pi_auth', 'status' => $status]);
    }

    private function postSigned(array $event)
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $secret = 'whsec_authorization_test';
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
