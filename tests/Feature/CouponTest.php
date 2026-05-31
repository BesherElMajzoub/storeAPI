<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class CouponTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->product = Product::factory()->create([
            'price'    => 100.00,
            'in_stock' => true,
            'status'   => 'published',
        ]);
    }

    // ── 1. Valid Percentage Coupon ───────────────────────────────────────────

    public function test_valid_percentage_coupon(): void
    {
        $coupon = Coupon::create([
            'code'        => 'PERCENT50',
            'type'        => 'percentage',
            'value'       => 50.00,
            'is_active'   => true,
            'starts_at'   => now()->subDay(),
            'expires_at'  => now()->addDay(),
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'PERCENT50',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'PERCENT50')
            ->assertJsonPath('data.subtotal', 100)
            ->assertJsonPath('data.discount', 50)
            ->assertJsonPath('data.total', 50);
    }

    // ── 2. Valid Fixed Coupon ────────────────────────────────────────────────

    public function test_valid_fixed_coupon(): void
    {
        $coupon = Coupon::create([
            'code'       => 'FIXED20',
            'type'       => 'fixed',
            'value'      => 20.00,
            'is_active'  => true,
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'FIXED20',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 2]
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'FIXED20')
            ->assertJsonPath('data.subtotal', 200)
            ->assertJsonPath('data.discount', 20)
            ->assertJsonPath('data.total', 180);
    }

    // ── 3. Expired Coupon ────────────────────────────────────────────────────

    public function test_expired_coupon(): void
    {
        $coupon = Coupon::create([
            'code'       => 'EXPIRED10',
            'type'       => 'fixed',
            'value'      => 10.00,
            'expires_at' => now()->subDay(),
            'is_active'  => true,
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'EXPIRED10',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Coupon has expired.');
    }

    // ── 4. Inactive Coupon ───────────────────────────────────────────────────

    public function test_inactive_coupon(): void
    {
        $coupon = Coupon::create([
            'code'      => 'INACTIVE',
            'type'      => 'fixed',
            'value'     => 10.00,
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'INACTIVE',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid coupon code.');
    }

    // ── 5. Minimum Amount Failure ────────────────────────────────────────────

    public function test_minimum_amount_failure(): void
    {
        $coupon = Coupon::create([
            'code'                 => 'MIN150',
            'type'                 => 'fixed',
            'value'                => 15.00,
            'minimum_order_amount' => 150.00,
            'is_active'            => true,
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'MIN150',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Minimum order amount not met.');
    }

    // ── 6. Usage Limit Failure ───────────────────────────────────────────────

    public function test_usage_limit_failure(): void
    {
        $coupon = Coupon::create([
            'code'        => 'LIMIT_REACHED',
            'type'        => 'fixed',
            'value'       => 10.00,
            'usage_limit' => 5,
            'used_count'  => 5,
            'is_active'   => true,
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'LIMIT_REACHED',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Coupon usage limit reached.');
    }

    // ── 7. Per-User Usage Limit Failure ──────────────────────────────────────

    public function test_per_user_usage_limit_failure(): void
    {
        $coupon = Coupon::create([
            'code'                 => 'ONCE_PER_USER',
            'type'                 => 'fixed',
            'value'                => 10.00,
            'usage_limit_per_user' => 1,
            'is_active'            => true,
        ]);

        // Scenario 1: Guest tries to validate coupon with per-user limit
        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'ONCE_PER_USER',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1]
            ]
        ]);
        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Authentication required to use this coupon.');

        // Scenario 2: Authenticated user has already used the coupon
        CouponUsage::create([
            'coupon_id'       => $coupon->id,
            'user_id'         => $this->user->id,
            'discount_amount' => 10.00,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/coupons/validate', [
                'code'  => 'ONCE_PER_USER',
                'items' => [
                    ['product_id' => $this->product->id, 'quantity' => 1]
                ]
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You have exceeded the usage limit for this coupon.');
    }

    // ── 8. Max Discount Cap ──────────────────────────────────────────────────

    public function test_max_discount_cap(): void
    {
        $coupon = Coupon::create([
            'code'                    => 'CAPPED50PERCENT',
            'type'                    => 'percentage',
            'value'                   => 50.00,
            'maximum_discount_amount' => 30.00,
            'is_active'               => true,
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'CAPPED50PERCENT',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1] // subtotal 100.00
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.discount', 30) // 50% of 100 is 50, but capped at 30
            ->assertJsonPath('data.total', 70);
    }

    // ── 9. Discount Not Exceeding Subtotal ───────────────────────────────────

    public function test_discount_not_exceeding_subtotal(): void
    {
        $coupon = Coupon::create([
            'code'      => 'HUGE_DISCOUNT',
            'type'      => 'fixed',
            'value'     => 150.00,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/coupons/validate', [
            'code'  => 'HUGE_DISCOUNT',
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 1] // subtotal 100.00
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.discount', 100) // discount capped at subtotal
            ->assertJsonPath('data.total', 0);
    }

    // ── 10. Order Creation with Coupon ───────────────────────────────────────

    public function test_order_creation_with_coupon(): void
    {
        $coupon = Coupon::create([
            'code'      => 'WELCOME10',
            'type'      => 'fixed',
            'value'     => 10.00,
            'is_active' => true,
        ]);

        $mockSession = StripeSession::constructFrom([
            'id'  => 'cs_test_123',
            'url' => 'https://checkout.stripe.com/pay/cs_test_123',
        ]);

        $this->mock(StripeCheckoutService::class, function ($mock) use ($mockSession) {
            $mock->shouldReceive('createCheckoutSession')->once()->andReturn($mockSession);
        });

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/orders', [
                'coupon_code'      => 'WELCOME10',
                'items'            => [['product_id' => $this->product->id, 'quantity' => 1]],
                'shipping_address' => ['name' => 'John Doe', 'line1' => '123 St', 'city' => 'NYC', 'country' => 'US'],
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.order.discount', 10)
            ->assertJsonPath('data.order.total', 90)
            ->assertJsonPath('data.order.coupon_code', 'WELCOME10');

        $this->assertDatabaseHas('orders', [
            'user_id'     => $this->user->id,
            'coupon_code' => 'WELCOME10',
            'discount'    => 10.00,
            'total'       => 90.00,
        ]);

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id'       => $coupon->id,
            'user_id'         => $this->user->id,
            'discount_amount' => 10.00,
        ]);

        $this->assertEquals(1, $coupon->fresh()->used_count);
    }

    // ── 11. Stripe Checkout Amount After Discount ────────────────────────────

    public function test_stripe_checkout_amount_after_discount(): void
    {
        $coupon = Coupon::create([
            'code'      => 'PERCENT10',
            'type'      => 'percentage',
            'value'     => 10.00,
            'is_active' => true,
        ]);

        $mockSession = StripeSession::constructFrom([
            'id'  => 'cs_test_abc',
            'url' => 'https://checkout.stripe.com/pay/cs_test_abc',
        ]);

        // Capture the order passed to the service during mock
        $this->mock(StripeCheckoutService::class, function ($mock) use ($mockSession) {
            $mock->shouldReceive('createCheckoutSession')
                ->once()
                ->with(Mockery::on(function (Order $order) {
                    // Verify that the order has the correct totals before creating Stripe session
                    return $order->discount == 10.00 && $order->total == 90.00 && $order->coupon_code === 'PERCENT10';
                }))
                ->andReturn($mockSession);
        });

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/orders', [
                'coupon_code'      => 'PERCENT10',
                'items'            => [['product_id' => $this->product->id, 'quantity' => 1]],
                'shipping_address' => ['name' => 'John Doe', 'line1' => '123 St', 'city' => 'NYC', 'country' => 'US'],
            ]);

        $response->assertStatus(201);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
