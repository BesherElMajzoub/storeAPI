<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Product;
use App\Models\ShippingRateQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCancelTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_directly_cancel_a_real_pending_payment_order_within_the_window(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['stock_qty' => 5, 'price' => 25]);
        $shipping = $this->createShippingQuote($product);

        $created = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => $shipping['items'],
            'shipping_address' => $shipping['address'],
            'shipping_rate_id' => $shipping['rateId'],
        ])->assertCreated();

        $orderId = $created->json('data.order.id');
        $this->assertSame('pending_payment', Order::find($orderId)->status);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/orders/{$orderId}/cancel")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'cancelled',
        ]);
    }

    public function test_customer_cannot_directly_cancel_after_the_three_hour_window(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'created_at' => now()->subHours(4),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(400);

        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_cancelling_an_unpaid_order_releases_its_coupon_usage_and_shipping_quote(): void
    {
        $user = User::factory()->create();
        $coupon = Coupon::create([
            'code' => 'SAVE10',
            'type' => 'fixed',
            'value' => 10,
            'usage_limit' => 5,
            'used_count' => 1,
            'usage_limit_per_user' => 1,
            'is_active' => true,
        ]);
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'coupon_id' => $coupon->id,
        ]);
        $usage = CouponUsage::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'order_id' => $order->id,
            'discount_amount' => 10,
        ]);
        $quote = ShippingRateQuote::create([
            'rate_id' => 'rate_test1',
            'shipment_id' => 'shp_test1',
            'carrier' => 'USPS',
            'service' => 'Priority',
            'amount' => 5.50,
            'currency' => 'usd',
            'address_hash' => 'addrhash',
            'items_hash' => 'itemshash',
            'parcel_hash' => 'parcelhash',
            'expires_at' => now()->addMinutes(15),
            'consumed_at' => now(),
            'order_id' => $order->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertOk();

        $this->assertSame(0, $coupon->fresh()->used_count);
        $this->assertNull(CouponUsage::find($usage->id));
        $freshQuote = $quote->fresh();
        $this->assertNull($freshQuote->order_id);
        $this->assertNull($freshQuote->consumed_at);
    }

    public function test_customer_cannot_directly_cancel_a_processing_order(): void
    {
        $user = User::factory()->create();
        $order = Order::factory()->create([
            'user_id' => $user->id,
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancel")
            ->assertStatus(400);

        $this->assertSame('processing', $order->fresh()->status);
    }
}
