<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\FreeShippingService;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class FreeShippingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->admin = User::factory()->create();
        $adminRole = Role::firstOrCreate(['name' => 'Admin'], ['label' => 'Admin']);
        $this->admin->roles()->attach($adminRole);

        $this->product = Product::factory()->create([
            'price' => 100.00,
            'in_stock' => true,
            'status' => 'published',
        ]);

        $this->mock(StripeCheckoutService::class, function ($mock) {
            $mock->shouldReceive('createCheckoutSession')->andReturn(
                StripeSession::constructFrom(['id' => 'cs_test_free_ship', 'url' => 'https://checkout.stripe.com/pay/cs_test_free_ship'])
            );
        });
    }

    private function placeOrder(array $extra = []): TestResponse
    {
        $shipping = $this->createShippingQuote($this->product, quantity: 1, amount: 12.50);

        return $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/orders', array_merge([
            'items' => $shipping['items'],
            'shipping_address' => $shipping['address'],
            'shipping_rate_id' => $shipping['rateId'],
        ], $extra));
    }

    // ── Admin settings ───────────────────────────────────────────────────────

    public function test_admin_can_read_default_shipping_settings(): void
    {
        // Business default (no settings row yet): free shipping on orders >= $100.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/settings/shipping')
            ->assertOk()
            ->assertJsonPath('data.free_shipping_enabled', true)
            ->assertJsonPath('data.free_shipping_threshold', 100);
    }

    public function test_public_endpoint_reports_the_current_free_shipping_offer(): void
    {
        $this->getJson('/api/v1/shipping/free-shipping')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.threshold', 100);

        app(FreeShippingService::class)->update(false, null);

        $this->getJson('/api/v1/shipping/free-shipping')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.threshold', null);
    }

    public function test_admin_can_enable_automatic_free_shipping_with_a_threshold(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings/shipping', [
                'free_shipping_enabled' => true,
                'free_shipping_threshold' => 100,
            ])
            ->assertOk()
            ->assertJsonPath('data.free_shipping_enabled', true)
            ->assertJsonPath('data.free_shipping_threshold', 100);

        $this->assertTrue(app(FreeShippingService::class)->isEnabled());
        $this->assertSame(100.0, app(FreeShippingService::class)->threshold());
    }

    public function test_enabling_automatic_free_shipping_without_a_threshold_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/settings/shipping', ['free_shipping_enabled' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['free_shipping_threshold']);
    }

    public function test_non_admin_cannot_read_or_update_shipping_settings(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/admin/settings/shipping')
            ->assertForbidden();

        $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/v1/admin/settings/shipping', ['free_shipping_enabled' => false])
            ->assertForbidden();
    }

    // ── Automatic threshold ──────────────────────────────────────────────────

    public function test_automatic_free_shipping_applies_when_subtotal_meets_the_threshold(): void
    {
        app(FreeShippingService::class)->update(true, 100.0);

        $response = $this->placeOrder();

        $response->assertStatus(201);
        $order = Order::first();
        $this->assertSame(0.0, (float) $order->shipping_cost);
        $this->assertSame(12.5, (float) $order->carrier_shipping_cost);
        $this->assertSame('threshold', $order->free_shipping_reason);
        $this->assertSame((float) $order->subtotal, (float) $order->total);
    }

    public function test_automatic_free_shipping_does_not_apply_below_the_threshold(): void
    {
        app(FreeShippingService::class)->update(true, 200.0);

        $response = $this->placeOrder();

        $response->assertStatus(201);
        $order = Order::first();
        $this->assertSame(12.5, (float) $order->shipping_cost);
        $this->assertNull($order->free_shipping_reason);
    }

    public function test_threshold_boundary_is_inclusive(): void
    {
        // Product price is 100.00, quantity 1 -> subtotal exactly 100.
        app(FreeShippingService::class)->update(true, 100.0);

        $this->placeOrder()->assertStatus(201);

        $order = Order::first();
        $this->assertSame(0.0, (float) $order->shipping_cost);
        $this->assertSame('threshold', $order->free_shipping_reason);
    }

    public function test_disabled_automatic_free_shipping_charges_normally_even_above_threshold(): void
    {
        app(FreeShippingService::class)->update(false, 50.0);

        $this->placeOrder()->assertStatus(201);

        $order = Order::first();
        $this->assertSame(12.5, (float) $order->shipping_cost);
        $this->assertNull($order->free_shipping_reason);
    }

    // ── Free-shipping coupon ─────────────────────────────────────────────────

    public function test_free_shipping_coupon_waives_shipping_without_discounting_subtotal(): void
    {
        // Isolate from the seeded $100 automatic threshold to test the coupon path alone.
        app(FreeShippingService::class)->update(false, null);

        Coupon::create([
            'code' => 'FREESHIP',
            'type' => 'free_shipping',
            'value' => 0,
            'is_active' => true,
        ]);

        $response = $this->placeOrder(['coupon_code' => 'FREESHIP']);

        $response->assertStatus(201);
        $order = Order::first();
        $this->assertSame(0.0, (float) $order->shipping_cost);
        $this->assertSame(12.5, (float) $order->carrier_shipping_cost);
        $this->assertSame('coupon', $order->free_shipping_reason);
        $this->assertSame(0.0, (float) $order->discount);
        $this->assertSame((float) $order->subtotal, (float) $order->total);
    }

    public function test_free_shipping_coupon_still_enforces_minimum_order_amount(): void
    {
        Coupon::create([
            'code' => 'FREESHIP50',
            'type' => 'free_shipping',
            'value' => 0,
            'minimum_order_amount' => 500,
            'is_active' => true,
        ]);

        $this->placeOrder(['coupon_code' => 'FREESHIP50'])
            ->assertStatus(422)
            ->assertJsonPath('errors.coupon_code.0', 'Minimum order amount not met.');
    }

    public function test_both_free_shipping_methods_together_do_not_stack_or_error(): void
    {
        app(FreeShippingService::class)->update(true, 50.0);
        Coupon::create([
            'code' => 'FREESHIP',
            'type' => 'free_shipping',
            'value' => 0,
            'is_active' => true,
        ]);

        $this->placeOrder(['coupon_code' => 'FREESHIP'])->assertStatus(201);

        $order = Order::first();
        $this->assertSame(0.0, (float) $order->shipping_cost);
        // Automatic threshold is evaluated first per the calculation order.
        $this->assertSame('threshold', $order->free_shipping_reason);
    }

    public function test_public_coupon_validate_reports_free_shipping_flag(): void
    {
        Coupon::create([
            'code' => 'FREESHIP',
            'type' => 'free_shipping',
            'value' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/coupons/validate', [
                'code' => 'FREESHIP',
                'items' => [['product_id' => $this->product->id, 'quantity' => 1]],
            ])
            ->assertOk()
            ->assertJsonPath('data.free_shipping', true)
            ->assertJsonPath('data.discount', 0);
    }

    public function test_admin_can_create_a_free_shipping_coupon_without_a_value(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/coupons', [
                'code' => 'ADMINFREESHIP',
                'type' => 'free_shipping',
                'is_active' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.type', 'free_shipping')
            ->assertJsonPath('data.free_shipping', true);

        $this->assertDatabaseHas('coupons', ['code' => 'ADMINFREESHIP', 'type' => 'free_shipping']);
    }

    public function test_resuming_payment_on_a_free_shipping_order_keeps_shipping_waived(): void
    {
        app(FreeShippingService::class)->update(true, 50.0);

        $order = $this->placeOrder()->assertStatus(201)->json('data.order');
        $this->assertSame(0.0, (float) Order::find($order['id'])->shipping_cost);

        $this->mock(StripeCheckoutService::class, function ($mock) {
            $mock->shouldReceive('retrieveCheckoutSession')->andReturn(
                StripeSession::constructFrom(['id' => 'cs_test_free_ship', 'status' => 'open', 'url' => 'https://checkout.stripe.com/pay/cs_test_free_ship'])
            );
        });

        $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order['id']}/checkout-session")
            ->assertOk();

        $refreshed = Order::find($order['id']);
        $this->assertSame(0.0, (float) $refreshed->shipping_cost);
        $this->assertSame('threshold', $refreshed->free_shipping_reason);
    }
}
