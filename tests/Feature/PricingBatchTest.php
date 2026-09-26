<?php

namespace Tests\Feature;

use App\Jobs\SendAdminAlert;
use App\Mail\OrderPaidMail;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\FreeShippingService;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class PricingBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->product = Product::factory()->create(['price' => 100, 'stock_qty' => 5, 'in_stock' => true]);
        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('createCheckoutSession')->zeroOrMoreTimes()->andReturn(
                StripeSession::constructFrom(['id' => 'cs_pricing', 'url' => 'https://checkout.stripe.com/cs_pricing'])
            );
        });
        Mail::fake();
        Queue::fake();
    }

    public function test_full_discount_creates_a_paid_free_order_without_stripe(): void
    {
        $coupon = Coupon::create(['code' => 'FREE100', 'type' => 'percentage', 'value' => 100, 'is_active' => true]);
        $shipping = $this->createShippingQuote($this->product, amount: 0);
        $response = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', [
            'coupon_code' => $coupon->code, 'items' => $shipping['items'],
            'shipping_address' => $shipping['address'], 'shipping_rate_id' => $shipping['rateId'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.checkout_url', null)
            ->assertJsonPath('data.payment_required', false)
            ->assertJsonPath('data.payment.session_id', null);
        $order = Order::firstOrFail();
        $this->assertSame('processing', $order->status);
        $this->assertSame('paid', $order->payment_status);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'payment_provider' => 'free', 'status' => 'completed', 'amount' => 0]);
        $this->assertDatabaseHas('coupon_usages', ['coupon_id' => $coupon->id, 'order_id' => $order->id]);
        Mail::assertQueued(OrderPaidMail::class, 1);
        Queue::assertPushed(SendAdminAlert::class, 1);
    }

    public function test_second_use_of_single_use_free_coupon_is_rejected_without_side_effects(): void
    {
        $coupon = Coupon::create(['code' => 'ONCEFREE', 'type' => 'percentage', 'value' => 100, 'usage_limit' => 1, 'is_active' => true]);
        $shipping = $this->createShippingQuote($this->product, amount: 0);
        $payload = ['coupon_code' => $coupon->code, 'items' => $shipping['items'], 'shipping_address' => $shipping['address'], 'shipping_rate_id' => $shipping['rateId']];
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', $payload)->assertCreated();
        $stockAfterFirst = $this->product->fresh()->stock_qty;

        $secondQuote = $this->createShippingQuote($this->product, amount: 0);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', array_merge($payload, [
            'items' => $secondQuote['items'], 'shipping_rate_id' => $secondQuote['rateId'],
        ]))->assertStatus(422)->assertJsonPath('message', 'Coupon usage limit reached.');
        $this->assertSame($stockAfterFirst, $this->product->fresh()->stock_qty);
        $this->assertSame(1, CouponUsage::where('coupon_id', $coupon->id)->count());
        $this->assertSame(1, Order::count());
    }

    public function test_below_minimum_total_is_rejected_before_order_or_stock_changes(): void
    {
        $this->product->update(['price' => 0.25]);
        $shipping = $this->createShippingQuote($this->product, amount: 0);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => $shipping['items'], 'shipping_address' => $shipping['address'], 'shipping_rate_id' => $shipping['rateId'],
        ])->assertStatus(422)->assertJsonPath('errors.code', 'minimum_charge');
        $this->assertSame(0, Order::count());
        $this->assertSame(5, $this->product->fresh()->stock_qty);
    }

    public function test_free_order_cannot_be_refunded_by_admin(): void
    {
        $admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin'], ['label' => 'Admin']);
        $admin->roles()->attach($role);
        $order = Order::factory()->create(['user_id' => $this->user->id, 'status' => 'processing', 'payment_status' => 'paid', 'total' => 0]);
        Payment::create(['order_id' => $order->id, 'payment_provider' => 'free', 'status' => 'completed', 'amount' => 0]);
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertStatus(409)->assertJsonPath('message', 'No Stripe PaymentIntent found for this order.');
    }

    public function test_free_shipping_threshold_is_based_on_discounted_subtotal(): void
    {
        app(FreeShippingService::class)->update(true, 100);
        $coupon = Coupon::create(['code' => 'UNDERTHRESHOLD', 'type' => 'fixed', 'value' => 1, 'is_active' => true]);
        $shipping = $this->createShippingQuote($this->product, amount: 10);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', [
            'coupon_code' => $coupon->code, 'items' => $shipping['items'], 'shipping_address' => $shipping['address'], 'shipping_rate_id' => $shipping['rateId'],
        ])->assertCreated();
        $order = Order::firstOrFail();
        $this->assertSame(10.0, (float) $order->shipping_cost);
        $this->assertNull($order->free_shipping_reason);
    }

    public function test_free_shipping_threshold_minus_one_cent_charges_shipping(): void
    {
        app(FreeShippingService::class)->update(true, 100);
        $this->product->update(['price' => 99.99]);
        $shipping = $this->createShippingQuote($this->product, amount: 10);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => $shipping['items'], 'shipping_address' => $shipping['address'], 'shipping_rate_id' => $shipping['rateId'],
        ])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(10.0, (float) $order->shipping_cost);
        $this->assertNull($order->free_shipping_reason);
    }

    public function test_free_shipping_threshold_exactly_is_free(): void
    {
        app(FreeShippingService::class)->update(true, 100);
        $shipping = $this->createShippingQuote($this->product, amount: 10);

        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => $shipping['items'], 'shipping_address' => $shipping['address'], 'shipping_rate_id' => $shipping['rateId'],
        ])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(0.0, (float) $order->shipping_cost);
        $this->assertSame('threshold', $order->free_shipping_reason);
    }
}
