<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

class SpaAuthTest extends TestCase
{
    use RefreshDatabase;

    private const FRONTEND_ORIGIN = 'http://localhost';

    public function test_ensure_frontend_requests_are_stateful_is_wired_into_the_api_group(): void
    {
        $middleware = app('router')->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(
            EnsureFrontendRequestsAreStateful::class,
            $middleware,
            'Sanctum stateful middleware must be prepended to the api middleware group.'
        );
    }

    public function test_login_from_the_frontend_sets_an_httponly_session_cookie(): void
    {
        $user = User::factory()->create(['password' => 'Password123!']);

        $response = $this->withHeaders(['Referer' => self::FRONTEND_ORIGIN])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        // Bearer-token issuance is preserved for non-browser clients.
        $this->assertNotEmpty($response->json('data.access_token'));

        $sessionCookieName = config('session.cookie');
        $sessionCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === $sessionCookieName);

        $this->assertNotNull($sessionCookie, 'Login from a stateful origin must set the session cookie.');
        $this->assertTrue($sessionCookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $sessionCookie->getSameSite()));

        $xsrfCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');
        $this->assertNotNull($xsrfCookie, 'A CSRF cookie must also be issued for the frontend to read.');
        $this->assertFalse($xsrfCookie->isHttpOnly(), 'The XSRF cookie must be JS-readable to be echoed back as a header.');
    }

    public function test_session_cookie_alone_authenticates_protected_routes_without_a_bearer_token(): void
    {
        $user = User::factory()->create();

        // Simulates an authenticated stateful (cookie) session -- no Authorization header sent.
        $this->actingAs($user, 'web')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_full_cookie_round_trip_login_access_then_logout_revokes_the_session(): void
    {
        $user = User::factory()->create(['password' => 'Password123!']);

        $login = $this->withHeaders(['Referer' => self::FRONTEND_ORIGIN])
            ->postJson('/api/v1/auth/login', [
                'email' => $user->email,
                'password' => 'Password123!',
            ])->assertOk();

        $cookies = collect($login->headers->getCookies())
            ->mapWithKeys(fn ($cookie) => [$cookie->getName() => $cookie->getValue()])
            ->all();

        // Access a protected route using only the session cookie -- no Authorization header.
        $this->withHeaders(['Referer' => self::FRONTEND_ORIGIN])
            ->withCookies($cookies)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        // A guard instance (and its resolved user) is cached in the container
        // for the rest of this test method; a real browser never reuses a PHP
        // process across requests, so force a fresh guard resolution here.
        Auth::forgetGuards();

        $this->withHeaders(['Referer' => self::FRONTEND_ORIGIN])
            ->withCookies($cookies)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        Auth::forgetGuards();

        // logout() invalidates and regenerates the session server-side, so
        // replaying the pre-logout cookie must no longer authenticate.
        $this->withHeaders(['Referer' => self::FRONTEND_ORIGIN])
            ->withCookies($cookies)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    public function test_non_frontend_requests_are_unaffected_and_still_use_bearer_tokens(): void
    {
        $user = User::factory()->create(['password' => 'Password123!']);

        // No Referer/Origin header -- not recognized as a frontend request,
        // so no session cookie is issued and CSRF is never enforced.
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'Password123!',
        ])->assertOk();

        $sessionCookieName = config('session.cookie');
        $hasSessionCookie = collect($response->headers->getCookies())
            ->contains(fn ($cookie) => $cookie->getName() === $sessionCookieName);
        $this->assertFalse($hasSessionCookie, 'A non-frontend request must not receive a session cookie.');

        $token = $response->json('data.access_token');
        $this->assertNotEmpty($token);

        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_unauthenticated_checkout_is_rejected(): void
    {
        $this->postJson('/api/v1/orders', [
            'items' => [],
            'shipping_address' => [],
            'shipping_rate_id' => 'rate_x',
        ])->assertUnauthorized();
    }

    public function test_order_ownership_cannot_be_bypassed_by_another_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $order = Order::factory()->for($owner)->create();

        $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/v1/orders/{$order->id}")
            ->assertNotFound();
    }
}
