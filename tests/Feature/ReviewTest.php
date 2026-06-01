<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewTest extends TestCase
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

        $this->product = Product::create([
            'name' => 'Premium Golden Dress',
            'slug' => 'premium-golden-dress',
            'price' => 150.00,
            'stock_qty' => 20,
            'in_stock' => true,
            'status' => 'published',
        ]);
    }

    /**
     * Test that a user cannot review a product they have not purchased.
     */
    public function test_user_cannot_review_product_without_purchased_order(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/reviews", [
                'rating' => 5,
                'comment' => 'This dress looks absolutely beautiful!',
            ]);

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'message' => 'You can only review products you have purchased.',
            ]);

        $this->assertDatabaseCount('reviews', 0);
    }

    /**
     * Test that a user can review a product after purchasing it and order is delivered.
     */
    public function test_user_can_review_product_after_delivered_purchase(): void
    {
        // 1. Create a delivered order for this user
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'delivered',
        ]);

        // 2. Add product to the order items
        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 150.00,
            'quantity' => 1,
            'total' => 150.00,
        ]);

        // 3. Post a review
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/products/{$this->product->id}/reviews", [
                'rating' => 5,
                'comment' => 'Excellent fabric, fits perfectly!',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rating', 5)
            ->assertJsonPath('data.comment', 'Excellent fabric, fits perfectly!');

        $this->assertDatabaseCount('reviews', 1);
    }

    /**
     * Test that admin can see product details in the review listing.
     */
    public function test_admin_can_see_product_details_in_reviews_list(): void
    {
        // 1. Create a review
        $review = Review::create([
            'user_id' => $this->user->id,
            'product_id' => $this->product->id,
            'rating' => 4,
            'comment' => 'Beautiful dress!',
            'status' => 'pending',
        ]);

        // 2. Get reviews list as admin
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reviews');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'rating',
                            'comment',
                            'status',
                            'product' => [
                                'id',
                                'name',
                                'slug',
                                'price',
                            ]
                        ]
                    ]
                ]
            ]);
    }
}
