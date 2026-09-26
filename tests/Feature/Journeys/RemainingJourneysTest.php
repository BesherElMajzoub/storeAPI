<?php

namespace Tests\Feature\Journeys;

use App\Jobs\SendAdminAlert;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * HTTP smoke journeys for the paths not covered by the two deep journeys.
 * Provider calls are deliberately represented by their public HTTP contracts;
 * provider-specific success paths remain covered by the focused contract tests.
 */
class RemainingJourneysTest extends TestCase
{
    use RefreshDatabase;

    public function test_j01_guest_can_browse_catalogue(): void
    {
        $this->getJson('/api/v1/categories')->assertOk();
        $this->getJson('/api/v1/products')->assertOk();
    }

    public function test_j02_customer_auth_lifecycle_is_http_only(): void
    {
        $credentials = ['name' => 'Journey User', 'email' => 'j02@example.test', 'password' => 'Password123!', 'password_confirmation' => 'Password123!'];
        $token = $this->postJson('/api/v1/auth/register', $credentials)->assertCreated()->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
    }

    public function test_j03_password_reset_request_has_a_stable_contract(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
    }

    public function test_j05_invalid_coupon_is_rejected_before_checkout(): void
    {
        $this->postJson('/api/v1/coupons/validate', ['code' => 'NOT-A-COUPON', 'subtotal' => 100])->assertStatus(422);
    }

    public function test_j06_unsigned_payment_webhook_is_rejected(): void
    {
        $this->postJson('/api/v1/webhooks/stripe', [])->assertStatus(400);
    }

    public function test_j07_repeated_invalid_webhooks_never_change_state(): void
    {
        $this->postJson('/api/v1/webhooks/stripe', [])->assertStatus(400);
        $this->postJson('/api/v1/webhooks/stripe', [])->assertStatus(400);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_j09_unknown_tracking_number_is_not_disclosed(): void
    {
        $this->postJson('/api/v1/orders/track', ['order_number' => 'missing-order', 'email' => 'nobody@example.test'])->assertNotFound();
    }

    public function test_j10_customer_cannot_cancel_another_customers_order(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $order = Order::factory()->for($owner)->create(['status' => 'pending_payment', 'payment_status' => 'unpaid']);
        $this->actingAs($attacker, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertNotFound();
    }

    public function test_j11_admin_catalog_requires_authorization(): void
    {
        $this->getJson('/api/v1/admin/categories')->assertUnauthorized();
    }

    public function test_j12_admin_bulk_order_update_requires_authorization(): void
    {
        $this->postJson('/api/v1/admin/orders/bulk-status', ['order_ids' => [], 'status' => 'processing'])->assertUnauthorized();
    }

    public function test_j13_wishlist_and_review_endpoints_share_the_customer_session(): void
    {
        $user = User::factory()->create();
        $category = Category::create(['name' => 'Journey Category', 'slug' => 'journey-category', 'is_active' => true]);
        $product = Product::factory()->create(['status' => 'published', 'category_id' => $category->id]);
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/wishlist', ['product_id' => $product->id])->assertCreated();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/wishlist')->assertOk();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/products/{$product->id}/reviews", ['rating' => 5, 'comment' => 'Journey review'])->assertStatus(409);
    }

    public function test_j15_public_contact_and_lead_forms_are_http_journeys(): void
    {
        Queue::fake([SendAdminAlert::class]);
        $this->postJson('/api/v1/contact-messages', ['name' => 'Journey', 'email' => 'journey@example.test', 'message' => 'Hello'])->assertCreated();
        $this->postJson('/api/v1/inspired-leads', ['phone' => '+12025550199'])->assertCreated();
    }
}
