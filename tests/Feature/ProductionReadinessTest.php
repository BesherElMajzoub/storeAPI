<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Order;
use App\Models\OtpCode;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\WishlistItem;
use App\Services\OtpService;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class ProductionReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_admin_route_rejects_a_regular_customer(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'sanctum');

        $routes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/admin/'));

        $this->assertNotEmpty($routes);

        foreach ($routes as $route) {
            $method = collect($route->methods())->first(fn ($method) => $method !== 'HEAD');
            $uri = '/'.preg_replace('/\{[^}]+\}/', '999999', $route->uri());

            $response = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json']);
            $this->assertContains($response->status(), [401, 403], "{$method} {$uri} returned {$response->status()}");
        }
    }

    public function test_admin_demotion_takes_effect_with_the_existing_session(): void
    {
        $admin = User::factory()->create();
        $role = Role::create(['name' => 'Admin']);
        $admin->roles()->attach($role);

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/dashboard')->assertOk();

        $admin->roles()->detach($role);

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_a_revoked_token_cannot_be_used_after_logout(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited(): void
    {
        RateLimiter::clear('login-ip|127.0.0.1');
        RateLimiter::clear('login-account|nobody@example.com');
        $statuses = [];

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $statuses[] = $this->postJson('/api/v1/auth/login', [
                'email' => 'nobody@example.com',
                'password' => 'WrongPassword1',
            ])->status();
        }

        $this->assertContains(429, $statuses);
    }

    public function test_password_policy_requires_a_letter_and_a_number(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Password Test',
            'email' => 'password@example.com',
            'password' => 'onlyletters',
            'password_confirmation' => 'onlyletters',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_password_reset_token_is_single_use(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);
        $plainToken = Str::random(64);
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => hash('sha256', $plainToken),
            'created_at' => now(),
        ]);

        $payload = [
            'email' => $user->email,
            'token' => $plainToken,
            'password' => 'NewPassword1',
            'password_confirmation' => 'NewPassword1',
        ];

        $this->postJson('/api/v1/auth/reset-password', $payload)->assertOk();
        $this->postJson('/api/v1/auth/reset-password', $payload)->assertUnprocessable();
        $this->assertTrue(Hash::check('NewPassword1', $user->fresh()->password));
    }

    public function test_expired_sanctum_token_is_rejected(): void
    {
        config(['sanctum.expiration' => 1]);
        $user = User::factory()->create();
        $token = $user->createToken('expired')->plainTextToken;
        $user->tokens()->update(['created_at' => now()->subMinutes(2)]);
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_otp_is_single_use_and_locks_after_five_wrong_attempts(): void
    {
        $service = app(OtpService::class);
        $first = OtpCode::create([
            'identifier' => 'otp-one@example.com', 'purpose' => 'password_reset', 'channel' => 'email',
            'code_hash' => hash('sha256', '123456'), 'expires_at' => now()->addMinutes(5), 'max_attempts' => 5,
        ]);

        $this->assertSame('verified', $service->verify($first->identifier, 'password_reset', '123456')['status']);
        $this->assertSame('consumed', $service->verify($first->identifier, 'password_reset', '123456')['status']);

        $second = OtpCode::create([
            'identifier' => 'otp-two@example.com', 'purpose' => 'password_reset', 'channel' => 'email',
            'code_hash' => hash('sha256', '654321'), 'expires_at' => now()->addMinutes(5), 'max_attempts' => 5,
        ]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $service->verify($second->identifier, 'password_reset', '000000');
        }
        $this->assertSame('locked', $service->verify($second->identifier, 'password_reset', '654321')['status']);
    }

    public function test_google_token_requires_our_audience_and_a_verified_email(): void
    {
        config(['services.google.client_id' => 'our-client-id']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'aud' => 'another-client-id', 'iss' => 'https://accounts.google.com',
                'exp' => time() + 300, 'sub' => 'google-1', 'email' => 'google@example.com',
                'email_verified' => true,
            ]),
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'bad-audience'])->assertUnauthorized();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'aud' => 'our-client-id', 'iss' => 'https://accounts.google.com',
                'exp' => time() + 300, 'sub' => 'google-2', 'email' => 'unverified@example.com',
                'email_verified' => false,
            ]),
        ]);

        $this->postJson('/api/v1/auth/google', ['id_token' => 'unverified-email'])->assertUnauthorized();
    }

    public function test_customer_cannot_read_or_mutate_another_customers_resources(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();
        $order = Order::factory()->for($owner)->create();
        $address = Address::create([
            'user_id' => $owner->id,
            'type' => 'shipping',
            'name' => 'Owner',
            'phone' => '+12025550123',
            'line1' => 'Owner Street',
            'city' => 'New York',
            'country' => 'US',
        ]);
        $product = Product::factory()->create();
        WishlistItem::create(['user_id' => $owner->id, 'product_id' => $product->id]);

        $this->actingAs($attacker, 'sanctum');
        $this->getJson("/api/v1/orders/{$order->id}")->assertNotFound();
        $this->postJson("/api/v1/orders/{$order->id}/cancel")->assertNotFound();
        $this->putJson("/api/v1/profile/addresses/{$address->id}", ['city' => 'Damascus'])->assertForbidden();
        $this->deleteJson("/api/v1/profile/addresses/{$address->id}")->assertForbidden();
        $this->getJson('/api/v1/wishlist/count')->assertJsonPath('data.count', 0);
        $this->getJson("/api/v1/wishlist/check/{$product->id}")->assertJsonPath('data.in_wishlist', false);
        $this->deleteJson("/api/v1/wishlist/{$product->id}")->assertNotFound();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.email', $attacker->email);
        $this->putJson('/api/v1/auth/me', ['name' => 'Attacker Updated'])->assertOk();
        $this->assertNotSame('Attacker Updated', $owner->fresh()->name);
        $this->assertDatabaseHas('wishlist_items', ['user_id' => $owner->id, 'product_id' => $product->id]);
    }

    public function test_order_ignores_client_prices_and_reserves_database_stock(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 25, 'stock_qty' => 2]);
        $session = StripeSession::constructFrom(['id' => 'cs_price', 'url' => 'https://checkout.stripe.test/cs_price']);
        $this->mock(StripeCheckoutService::class, fn ($mock) => $mock->shouldReceive('createCheckoutSession')->once()->andReturn($session));
        $shipping = $this->createShippingQuote($product);

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [array_merge($shipping['items'][0], ['price' => 0])],
            'total' => 0,
            'shipping_address' => $shipping['address'],
            'shipping_rate_id' => $shipping['rateId'],
        ])->assertCreated();

        $orderId = $response->json('data.order.id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'subtotal' => 25, 'total' => 25]);
        $this->assertDatabaseHas('order_items', ['order_id' => $orderId, 'price' => 25, 'total' => 25]);
        $this->assertSame(1, $product->fresh()->stock_qty);
    }

    public function test_unverified_customer_cannot_checkout(): void
    {
        $user = User::factory()->unverified()->create();
        $product = Product::factory()->create();
        $shipping = $this->createShippingQuote($product);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => $shipping['items'],
            'shipping_address' => $shipping['address'],
            'shipping_rate_id' => $shipping['rateId'],
        ])->assertForbidden();
    }

    public function test_generic_admin_status_route_cannot_mark_an_order_paid_or_refunded(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $order = Order::factory()->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['payment_status' => 'paid'])
            ->assertUnprocessable();

        $this->postJson("/api/v1/admin/orders/{$order->id}/status", ['payment_status' => 'refunded'])
            ->assertUnprocessable();

        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_sort_type_confusion_and_injection_are_rejected_and_pagination_is_capped(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $this->actingAs($admin, 'sanctum');

        $this->getJson('/api/v1/admin/products?sort=price%3BDROP--')->assertUnprocessable();
        $this->getJson('/api/v1/admin/products?sort[]=price_asc')->assertUnprocessable();
        $this->getJson('/api/v1/admin/products?per_page=100000')->assertUnprocessable();
        $this->getJson('/api/v1/products?sort=price%3BDROP--')->assertUnprocessable();
        $this->getJson('/api/v1/products?sort[]=price_asc')->assertUnprocessable();
    }

    public function test_api_responses_include_security_headers_and_removed_test_routes_are_unreachable(): void
    {
        $this->getJson('/api/v1/products')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY');

        $this->get('/google-test')->assertNotFound();
        $this->post('/stripe/checkout')->assertNotFound();
    }
}
