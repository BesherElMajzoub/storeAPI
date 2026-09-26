<?php

namespace Tests\Feature\Journeys;

use App\Contracts\EasyPostServiceInterface;
use App\Mail\CancellationRequestDecidedMail;
use App\Mail\OrderPaidMail;
use App\Mail\OrderShippedMail;
use App\Mail\ResetPasswordMail;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\InspiredLead;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\FreeShippingService;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

/**
 * The remaining Phase 04 journeys. Every step after the initial fixture is a
 * real HTTP request; models are only used to establish the initial seed and to
 * assert the state transitions that the API produced.
 */
class RemainingJourneysTest extends TestCase
{
    use RefreshDatabase;

    public function test_j01_guest_browses_category_filters_detail_and_reviews_without_hidden_products(): void
    {
        $category = Category::create(['name' => 'Journey Catalog', 'slug' => 'journey-catalog', 'is_active' => true]);
        $visible = Product::factory()->create(['category_id' => $category->id, 'status' => 'published', 'price' => 42]);
        $hidden = Product::factory()->create(['category_id' => $category->id, 'status' => 'draft']);

        $this->getJson('/api/v1/categories')->assertOk()->assertJsonPath('data.0.slug', 'journey-catalog');
        $this->getJson('/api/v1/categories/journey-catalog')->assertOk()->assertJsonPath('data.slug', 'journey-catalog');

        $filtered = $this->getJson('/api/v1/products?category=journey-catalog&price_min=40&price_max=50&sort=price_asc&per_page=1')
            ->assertOk();
        $filtered->assertJsonPath('data.0.id', $visible->id);
        $this->assertSame(1, count($filtered->json('data')));
        $this->assertNotContains($hidden->id, collect($filtered->json('data'))->pluck('id')->all());

        $this->getJson("/api/v1/products/{$visible->slug}")->assertOk()->assertJsonPath('data.id', $visible->id);
        $this->getJson("/api/v1/products/{$visible->id}/reviews")->assertOk()->assertJsonPath('data', []);
    }

    public function test_j02_registration_otp_endpoint_login_me_logout_and_old_token_rejection(): void
    {
        $email = 'j02-'.uniqid().'@example.test';
        $password = 'Password123!';
        $registered = $this->postJson('/api/v1/auth/register', [
            'name' => 'Journey Customer', 'email' => $email, 'password' => $password,
            'password_confirmation' => $password,
        ])->assertCreated();
        $registeredToken = $registered->json('data.access_token');

        // Registration is immediately usable; OTP is an explicit optional
        // endpoint in this contract, so record the send step without inventing
        // a verification gate that the application does not enforce.
        $this->postJson('/api/v1/auth/otp/send', ['email' => $email, 'purpose' => 'email_verification'])
            ->assertOk();

        $login = $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk();
        $token = $login->json('data.access_token');
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $email);
        $this->withToken($token)->postJson('/api/v1/auth/logout', ['all' => true])->assertOk();
        $this->flushSession();
        Auth::forgetGuards();
        $this->withHeaders(['Cookie' => ''])->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->withHeaders(['Cookie' => ''])->withToken($registeredToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_j03_password_reset_uses_the_mail_token_and_invalidates_old_password(): void
    {
        Mail::fake();
        $oldPassword = 'OldPassword123!';
        $newPassword = 'NewPassword123!';
        $user = User::factory()->create(['password' => $oldPassword]);

        $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();
        $token = null;
        Mail::assertQueued(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$token, $user): bool {
            $token = $mail->token;

            return $mail->email === $user->email;
        });
        $this->assertNotEmpty($token);

        $this->postJson('/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $oldPassword])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => $newPassword])->assertOk();
    }

    public function test_j05_coupon_checkout_applies_free_shipping_and_records_exact_totals(): void
    {
        $this->setUpShippingMock('j05-shipment', 'j05-rate', 12.50);
        app(FreeShippingService::class)->update(false, null);
        $coupon = Coupon::create(['code' => 'J05SHIP', 'type' => 'free_shipping', 'value' => 0, 'is_active' => true]);
        $product = Product::factory()->create(['price' => 100, 'stock_qty' => 5, 'in_stock' => true]);
        [$token, $email] = $this->verifiedJourneyUser('j05');

        $this->withToken($token)->postJson('/api/v1/coupons/validate', [
            'code' => $coupon->code,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk()->assertJsonPath('data.free_shipping', true)->assertJsonPath('data.discount', 0);

        $order = $this->createOrderThroughHttp($token, $product, 'j05-rate', 'cs_j05', 'J05SHIP');
        $this->assertSame(100.0, (float) $order->subtotal);
        $this->assertSame(0.0, (float) $order->shipping_cost);
        $this->assertSame(100.0, (float) $order->total);
        $this->assertSame(1, CouponUsage::where('coupon_id', $coupon->id)->count());
    }

    public function test_j06_expired_payment_webhook_cancels_order_and_restores_stock_and_coupon(): void
    {
        Mail::fake();
        $this->setUpShippingMock('j06-shipment', 'j06-rate', 10.00);
        $coupon = Coupon::create(['code' => 'J06OFF', 'type' => 'fixed', 'value' => 5, 'is_active' => true]);
        $product = Product::factory()->create(['price' => 50, 'stock_qty' => 5, 'in_stock' => true]);
        [$token, $email] = $this->verifiedJourneyUser('j06');
        $order = $this->createOrderThroughHttp($token, $product, 'j06-rate', 'cs_j06', 'J06OFF');
        $this->assertSame(4, (int) $product->fresh()->stock_qty);
        $this->assertSame(1, CouponUsage::where('coupon_id', $coupon->id)->count());

        $this->postSignedStripeEvent([
            'id' => 'evt_j06_expired', 'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => 'cs_j06', 'metadata' => ['order_id' => (string) $order->id]]],
        ])->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('failed', $order->fresh()->payment_status);
        $this->assertSame(5, (int) $product->fresh()->stock_qty);
        $this->assertSame(0, CouponUsage::where('coupon_id', $coupon->id)->count());
    }

    public function test_j07_valid_duplicate_and_out_of_order_webhooks_are_idempotent(): void
    {
        Mail::fake();
        Queue::fake();
        $this->setUpShippingMock('j07-shipment', 'j07-rate', 5.00);
        $product = Product::factory()->create(['price' => 40, 'stock_qty' => 3, 'in_stock' => true]);
        [$token, $email] = $this->verifiedJourneyUser('j07');
        $order = $this->createOrderThroughHttp($token, $product, 'j07-rate', 'cs_j07');
        $payload = [
            'id' => 'evt_j07_paid', 'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_j07', 'payment_intent' => 'pi_j07', 'amount_total' => (int) round($order->total * 100),
                'currency' => 'usd', 'metadata' => ['order_id' => (string) $order->id],
            ]],
        ];
        $this->postSignedStripeEvent($payload)->assertOk();
        $this->postSignedStripeEvent($payload)->assertOk();
        $this->postSignedStripeEvent([
            'id' => 'evt_j07_late_expired', 'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => 'cs_j07', 'metadata' => ['order_id' => (string) $order->id]]],
        ])->assertOk();

        $this->assertSame('processing', $order->fresh()->status);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame(1, Payment::where('order_id', $order->id)->count());
        Mail::assertQueued(OrderPaidMail::class, 1);
    }

    public function test_j09_admin_shipping_webhook_and_public_tracking_chain(): void
    {
        Mail::fake();
        Queue::fake();
        $customer = User::factory()->create();
        $admin = $this->adminUser('j09-admin');
        $order = Order::factory()->for($customer)->create([
            'status' => 'processing', 'payment_status' => 'paid', 'total' => 55, 'subtotal' => 50,
            'shipping_cost' => 5, 'shipping_address' => ['name' => 'Customer', 'street' => '1 Main', 'city' => 'LA', 'country' => 'US'],
            'easypost_shipment_id' => 'shp_j09', 'shipping_rate_id' => 'rate_j09',
        ]);
        $order->items()->create(['product_id' => Product::factory()->create()->id, 'product_name' => 'Journey item', 'price' => 50, 'quantity' => 1, 'total' => 50]);
        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('purchaseLabel')->once()->with('shp_j09', 'rate_j09')->andReturn((object) [
                'tracking_code' => 'J09TRACK', 'postage_label' => (object) ['label_url' => 'https://labels.test/j09'],
                'tracker' => (object) ['public_url' => 'https://tracking.test/J09TRACK', 'status' => 'pre_transit'],
            ]);
        });
        $adminToken = $this->login($admin->email);
        $this->withToken($adminToken)->postJson("/api/v1/admin/orders/{$order->id}/label", [
            'shipment_id' => 'shp_j09', 'rate_id' => 'rate_j09',
        ])->assertOk()->assertJsonPath('data.tracking_number', 'J09TRACK');
        Mail::assertQueued(OrderShippedMail::class, 1);

        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('validateWebhook')->once()->andReturn((object) [
                'description' => 'tracker.updated',
                'result' => (object) ['tracking_code' => 'J09TRACK', 'status' => 'delivered'],
            ]);
        });
        config(['services.easypost.webhook_secret' => 'j09-secret']);
        $this->postJson('/api/v1/webhooks/easypost', ['description' => 'tracker.updated'])->assertOk();
        $this->postJson('/api/v1/orders/track', ['order_number' => $order->order_number, 'email' => $customer->email])
            ->assertOk()->assertJsonPath('data.status', 'delivered');
    }

    public function test_j10_customer_cancel_request_admin_accepts_and_rejects_with_invariants(): void
    {
        Mail::fake();
        $customer = User::factory()->create();
        $admin = $this->adminUser('j10-admin');
        $product = Product::factory()->create(['stock_qty' => 3, 'in_stock' => true]);
        $order = Order::factory()->for($customer)->create(['status' => 'processing', 'payment_status' => 'paid']);
        $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'price' => 20, 'quantity' => 2, 'total' => 40]);
        $product->update(['stock_qty' => 1]);

        $customerToken = $this->login($customer->email);
        $this->withToken($customerToken)->postJson("/api/v1/orders/{$order->id}/cancellation-request", [
            'reason' => 'I changed my mind and need this cancelled.',
        ])->assertCreated();
        $requestId = OrderCancellationRequest::where('order_id', $order->id)->value('id');
        $adminToken = $this->login($admin->email);
        $this->withToken($adminToken)->postJson("/api/v1/admin/cancellation-requests/{$requestId}/accept")
            ->assertOk();
        Mail::assertQueued(CancellationRequestDecidedMail::class, 1);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(3, (int) $product->fresh()->stock_qty);

        $rejectedOrder = Order::factory()->for($customer)->create(['status' => 'processing', 'payment_status' => 'paid']);
        $this->flushSession();
        Auth::forgetGuards();
        $rejected = $this->withToken($customerToken)->postJson("/api/v1/orders/{$rejectedOrder->id}/cancellation-request", [
            'reason' => 'Please cancel this second order as well.',
        ])->assertCreated();
        $rejectedId = OrderCancellationRequest::where('order_id', $rejectedOrder->id)->value('id');
        $this->flushSession();
        Auth::forgetGuards();
        $this->withToken($adminToken)->postJson("/api/v1/admin/cancellation-requests/{$rejectedId}/reject", [
            'admin_note' => 'The order has already been prepared for dispatch.',
        ])->assertOk();
        $this->assertSame('processing', $rejectedOrder->fresh()->status);
    }

    public function test_j11_admin_catalog_authoring_publish_stock_import_and_audit_chain(): void
    {
        $admin = $this->adminUser('j11-admin');
        $token = $this->login($admin->email);
        $category = $this->withToken($token)->postJson('/api/v1/admin/categories', [
            'name' => 'Journey Electronics', 'slug' => 'journey-electronics', 'is_active' => true,
        ])->assertCreated()->json('data');
        $categoryId = $category['id'];
        $this->withToken($token)->postJson('/api/v1/admin/categories/reorder', [
            'categories' => [['id' => $categoryId, 'parent_id' => null, 'sort_order' => 0]],
        ])->assertOk()->assertJsonPath('success', true);
        $product = $this->withToken($token)->postJson('/api/v1/admin/products', [
            'name' => 'Journey Camera', 'slug' => 'journey-camera', 'price' => 300, 'category_id' => $categoryId,
            'sku' => 'J11-CAMERA', 'stock_qty' => 4, 'status' => 'draft', 'in_stock' => true,
            'weight_oz' => 8, 'length_in' => 8, 'width_in' => 6, 'height_in' => 2,
            'variants' => [['name' => 'Black', 'sku' => 'J11-CAMERA-BLK', 'price' => 300, 'stock_qty' => 2]],
        ])->assertCreated()->json('data');
        $productId = $product['id'];
        $this->withToken($token)->post("/api/v1/admin/products/{$productId}/images", [
            'images' => [UploadedFile::fake()->image('journey-camera.jpg')],
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/v1/admin/skus/generate', [
            'type' => 'product', 'category_id' => $categoryId, 'product_name' => 'Journey Camera', 'product_slug' => 'journey-camera',
        ])->assertOk()->assertJsonPath('data.sku', fn ($sku) => is_string($sku) && $sku !== '');
        $this->withToken($token)->patchJson("/api/v1/admin/products/{$productId}", ['status' => 'published'])->assertOk();
        $this->getJson('/api/v1/products/journey-camera')->assertOk()->assertJsonPath('data.id', $productId);
        $this->withToken($token)->postJson("/api/v1/admin/products/{$productId}/stock/adjust", ['delta' => 2])->assertOk();

        $csv = "type,sku,name,price,stock_qty,status,category_slug,weight_oz,length_in,width_in,height_in\nproduct,J11-IMPORT,Imported Journey Product,15,2,draft,journey-electronics,8,8,6,2\n";
        $this->withToken($token)->post('/api/v1/admin/products/import', [
            'file' => UploadedFile::fake()->createWithContent('journey.csv', $csv), 'dry_run' => 'true',
        ])->assertOk()->assertJsonPath('data.summary.rows', 1);
        $this->withToken($token)->getJson('/api/v1/admin/audit-logs')->assertOk();
    }

    public function test_j12_admin_order_filter_and_bulk_update_reports_invalid_transition_without_partial_write(): void
    {
        $admin = $this->adminUser('j12-admin');
        $valid = Order::factory()->create(['status' => 'processing', 'payment_status' => 'paid']);
        $invalid = Order::factory()->create(['status' => 'delivered', 'payment_status' => 'paid']);
        $token = $this->login($admin->email);
        $this->withToken($token)->getJson('/api/v1/admin/orders?status=processing')->assertOk();
        $this->withToken($token)->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$valid->id, $invalid->id], 'status' => 'shipped',
        ])->assertStatus(409)->assertJsonPath('success', false);
        $this->assertSame('processing', $valid->fresh()->status);
        $this->assertSame('delivered', $invalid->fresh()->status);
    }

    public function test_j13_wishlist_purchase_review_moderation_and_rating_chain(): void
    {
        Mail::fake();
        $customer = User::factory()->create();
        $admin = $this->adminUser('j13-admin');
        $category = Category::create(['name' => 'Journey Reviews', 'slug' => 'journey-reviews', 'is_active' => true]);
        $product = Product::factory()->create(['category_id' => $category->id, 'price' => 25, 'stock_qty' => 3, 'status' => 'published', 'in_stock' => true]);
        $customerToken = $this->login($customer->email);
        $this->withToken($customerToken)->postJson('/api/v1/wishlist', ['product_id' => $product->id])->assertCreated();
        $this->withToken($customerToken)->getJson('/api/v1/wishlist')->assertOk()->assertJsonFragment(['id' => $product->id]);
        $this->withToken($customerToken)->deleteJson("/api/v1/wishlist/{$product->id}")->assertOk();

        $this->setUpShippingMock('j13-shipment', 'j13-rate', 5.00);
        $order = $this->createOrderThroughHttp($customerToken, $product, 'j13-rate', 'cs_j13');
        $this->postSignedStripeEvent([
            'id' => 'evt_j13_paid', 'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_j13', 'payment_intent' => 'pi_j13', 'amount_total' => (int) round($order->total * 100), 'currency' => 'usd', 'metadata' => ['order_id' => (string) $order->id]]],
        ])->assertOk();
        $adminToken = $this->login($admin->email);
        $this->withToken($adminToken)->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'shipped'])->assertOk();
        $this->withToken($adminToken)->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'delivered'])->assertOk();

        $this->flushSession();
        Auth::forgetGuards();
        $review = $this->withToken($customerToken)->postJson("/api/v1/products/{$product->id}/reviews", [
            'rating' => 5, 'comment' => 'Excellent journey product.',
        ])->assertCreated()->json('data');
        $this->flushSession();
        Auth::forgetGuards();
        $this->withToken($adminToken)->getJson('/api/v1/admin/reviews?status=pending')->assertOk();
        $this->withToken($adminToken)->patchJson("/api/v1/admin/reviews/{$review['id']}/moderate", ['action' => 'approve'])->assertOk();
        $this->assertSame(5.0, (float) $product->fresh()->rating);
    }

    public function test_j15_contact_and_lead_are_visible_to_admin_status_updated_and_throttled(): void
    {
        Queue::fake();
        $admin = $this->adminUser('j15-admin');
        $contact = $this->postJson('/api/v1/contact-messages', [
            'name' => 'Journey Contact', 'email' => 'j15@example.test', 'subject' => 'Question', 'message' => 'Please contact me.',
        ])->assertCreated();
        $lead = $this->postJson('/api/v1/inspired-leads', ['phone' => '+12025550999'])->assertCreated();
        $contactId = ContactMessage::latest('id')->value('id');
        $leadId = InspiredLead::latest('id')->value('id');
        $adminToken = $this->login($admin->email);
        $this->withToken($adminToken)->getJson('/api/v1/admin/contact-messages?search=j15@example.test')->assertOk();
        $this->withToken($adminToken)->patchJson("/api/v1/admin/contact-messages/{$contactId}/status", ['status' => 'replied', 'notes' => 'Answered by support.'])->assertOk();
        $this->withToken($adminToken)->getJson('/api/v1/admin/inspired-leads')->assertOk();
        $this->withToken($adminToken)->putJson("/api/v1/admin/inspired-leads/{$leadId}", ['status' => 'contacted', 'notes' => 'Called customer.'])->assertOk();
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/contact-messages', ['name' => 'Throttle', 'email' => "throttle{$i}@example.test", 'message' => 'A valid message.'])->assertCreated();
        }
        $this->postJson('/api/v1/contact-messages', ['name' => 'Throttle', 'email' => 'throttle-last@example.test', 'message' => 'A valid message.'])->assertTooManyRequests();
        $this->assertSame('replied', ContactMessage::find($contactId)->status);
        $this->assertSame('contacted', InspiredLead::find($leadId)->status);
    }

    private function registerJourneyUser(string $prefix): string
    {
        $email = $prefix.'-'.uniqid().'@example.test';
        $password = 'Password123!';

        return $this->postJson('/api/v1/auth/register', [
            'name' => strtoupper($prefix).' Journey', 'email' => $email, 'password' => $password, 'password_confirmation' => $password,
        ])->assertCreated()->json('data.access_token');
    }

    private function verifiedJourneyUser(string $prefix): array
    {
        $email = $prefix.'-'.uniqid().'@example.test';
        $user = User::factory()->create(['email' => $email, 'email_verified_at' => now()]);

        return [$this->login($user->email), $email];
    }

    private function login(string $email, string $password = 'password'): string
    {
        $this->flushSession();
        Auth::forgetGuards();
        Auth::shouldUse('web');

        return $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
            ->assertOk()->json('data.access_token');
    }

    private function adminUser(string $prefix): User
    {
        $admin = User::factory()->create(['email' => $prefix.'-'.uniqid().'@example.test']);
        $admin->roles()->syncWithoutDetaching([Role::firstOrCreate(['name' => 'Admin'], ['label' => 'Administrator'])->id]);

        return $admin;
    }

    private function setUpShippingMock(string $shipmentId, string $rateId, float $amount): void
    {
        $this->mock(EasyPostServiceInterface::class, function ($mock) use ($shipmentId, $rateId, $amount): void {
            $mock->shouldReceive('getShippingRates')->once()->andReturn((object) [
                'id' => $shipmentId,
                'rates' => [(object) ['id' => $rateId, 'shipment_id' => $shipmentId, 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => (string) $amount, 'currency' => 'USD', 'delivery_days' => 3]],
            ]);
            $mock->shouldReceive('retrieveRate')->zeroOrMoreTimes()->andReturn((object) [
                'id' => $rateId, 'shipment_id' => $shipmentId, 'rate' => (string) $amount, 'currency' => 'USD', 'carrier' => 'USPS', 'service' => 'Priority',
            ]);
        });
    }

    private function createOrderThroughHttp(string $token, Product $product, string $rateId, string $sessionId, ?string $coupon = null): Order
    {
        $this->mock(StripeCheckoutService::class, function ($mock) use ($sessionId): void {
            $mock->shouldReceive('createCheckoutSession')->once()->andReturn(StripeSession::constructFrom(['id' => $sessionId, 'url' => 'https://checkout.test/'.$sessionId]));
        });
        $rate = $this->withToken($token)->postJson('/api/v1/shipping/rates', [
            'address' => ['line1' => '1 Main Street', 'city' => 'Los Angeles', 'state' => 'CA', 'postal_code' => '90001', 'country' => 'US'],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk()->json('data.0.rate_id');
        $response = $this->withToken($token)->postJson('/api/v1/orders', array_filter([
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address' => ['name' => 'Journey Customer', 'line1' => '1 Main Street', 'city' => 'Los Angeles', 'state' => 'CA', 'postal_code' => '90001', 'country' => 'US'],
            'shipping_rate_id' => $rate ?: $rateId,
            'coupon_code' => $coupon,
        ], static fn ($value) => $value !== null));

        $response->assertCreated();

        return Order::findOrFail($response->json('data.order.id'));
    }

    private function postSignedStripeEvent(array $event)
    {
        $payload = json_encode(array_merge(['object' => 'event'], $event), JSON_THROW_ON_ERROR);
        $secret = 'journey-stripe-secret';
        config(['services.stripe.webhook_secret' => $secret, 'services.stripe.currency' => 'usd']);
        $timestamp = time();
        $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $payload);
    }
}
