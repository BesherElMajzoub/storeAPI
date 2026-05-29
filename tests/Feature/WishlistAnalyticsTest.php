<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\WishlistEvent;
use App\Models\WishlistItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WishlistAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Product $product;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 1. Create admin user and role
        $this->admin = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Admin'], ['label' => 'Admin']);
        $this->admin->roles()->attach($role);

        // 2. Create customer and a product
        $this->customer = User::factory()->create();
        $this->product = Product::factory()->create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 100.00,
        ]);
    }

    /**
     * Test admin index endpoint returns wishlist counts by product.
     */
    public function test_admin_wishlist_analytics_index(): void
    {
        WishlistItem::create([
            'user_id' => $this->customer->id,
            'product_id' => $this->product->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/wishlist-analytics');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'price',
                            'final_price',
                            'image',
                            'wishlist_count',
                        ]
                    ]
                ]
            ]);
    }

    /**
     * Test admin summary endpoint.
     */
    public function test_admin_wishlist_analytics_summary(): void
    {
        WishlistItem::create([
            'user_id' => $this->customer->id,
            'product_id' => $this->product->id,
        ]);

        WishlistEvent::create([
            'user_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'action' => 'added',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/wishlist-analytics/summary');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'total_wishlist_entries',
                    'unique_wishlisted_products',
                    'users_with_wishlist',
                    'top_product' => [
                        'id',
                        'name',
                        'wishlist_count',
                        'image',
                    ],
                    'this_week_adds',
                    'last_week_adds',
                    'growth_rate',
                ]
            ]);
    }

    /**
     * Test admin trending endpoint.
     */
    public function test_admin_wishlist_analytics_trending(): void
    {
        WishlistEvent::create([
            'user_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'action' => 'added',
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/wishlist-analytics/trending');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'price',
                            'final_price',
                            'image',
                            'recent_adds',
                        ]
                    ]
                ]
            ]);
    }

    /**
     * Test admin conversions endpoint.
     */
    public function test_admin_wishlist_analytics_conversions(): void
    {
        // Add to wishlist
        WishlistItem::create([
            'user_id' => $this->customer->id,
            'product_id' => $this->product->id,
        ]);

                // Place a delivered order to simulate a converted checkout
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-TEST-100',
            'user_id' => $this->customer->id,
            'status' => 'delivered',
            'subtotal' => 100.00,
            'total' => 100.00,
            'shipping_address' => json_encode(['address' => 'Test Street']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'quantity' => 1,
            'price' => 100.00,
            'total' => 100.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/v1/admin/wishlist-analytics/conversions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'price',
                            'image',
                            'total_wishlisted',
                            'total_converted',
                            'conversion_rate',
                        ]
                    ]
                ]
            ]);
    }
}
