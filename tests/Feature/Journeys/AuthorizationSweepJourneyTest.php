<?php

namespace Tests\Feature\Journeys;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * J14 - Authorization sweep: user B tries every user-A resource by id ->
 * 403/404, never data. Normal user hits every admin route -> 403.
 *
 * The admin-route sweep and the orders/addresses/wishlist/profile part of
 * the cross-user sweep are already covered by
 * ProductionReadinessTest::test_every_admin_route_rejects_a_regular_customer
 * and ::test_customer_cannot_read_or_mutate_another_customers_resources
 * (both pre-existing, still green). This journey adds the two pieces J14
 * asks for that weren't covered anywhere yet: reviews and cancellation
 * requests belonging to another customer.
 */
class AuthorizationSweepJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_b_cannot_touch_user_as_review_or_cancellation_request(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $product = Product::factory()->create(['status' => 'published']);

        $review = Review::create([
            'user_id' => $owner->id,
            'product_id' => $product->id,
            'rating' => 5,
            'comment' => 'Great product',
            'status' => 'approved',
            'is_verified_purchase' => false,
        ]);

        $order = Order::factory()->for($owner)->create([
            'status' => 'processing',
            'payment_status' => 'paid',
        ]);

        $this->actingAs($attacker, 'sanctum');

        // Attacker cannot update or delete another customer's review.
        $this->putJson("/api/v1/products/{$product->id}/reviews/{$review->id}", [
            'rating' => 1,
            'comment' => 'This review has been hijacked by an attacker.',
        ])->assertForbidden();

        $this->deleteJson("/api/v1/products/{$product->id}/reviews/{$review->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'rating' => 5,
            'comment' => 'Great product',
        ]);

        // Attacker cannot submit a cancellation request against another
        // customer's order — the order isn't even disclosed to exist.
        $this->postJson("/api/v1/orders/{$order->id}/cancellation-request", [
            'reason' => 'I want this cancelled please, thank you.',
        ])->assertNotFound();

        $this->assertDatabaseCount('order_cancellation_requests', 0);

        // Sanity: the owner can do all of the above on their own resources.
        $this->actingAs($owner, 'sanctum');

        $this->putJson("/api/v1/products/{$product->id}/reviews/{$review->id}", [
            'rating' => 4,
            'comment' => 'Updated by the real owner',
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$order->id}/cancellation-request", [
            'reason' => 'Changed my mind about this order.',
        ])->assertCreated();
    }
}
