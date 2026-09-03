<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PublicOrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_tracking_returns_only_the_documented_snapshot(): void
    {
        $user = User::factory()->create(['email' => 'buyer@example.com']);
        $order = Order::factory()->for($user)->create([
            'order_number' => 'OQ-10234',
            'status' => 'shipped',
            'shipment_status' => 'in_transit',
            'estimated_delivery' => '2026-09-04',
            'tracking_events' => [[
                'status' => 'in_transit',
                'description' => 'Departed USPS facility',
                'location' => 'Los Angeles, CA',
                'occurred_at' => '2026-09-01T20:11:00+00:00',
            ]],
        ]);

        $this->postJson('/api/v1/orders/track', [
            'order_number' => $order->order_number,
            'email' => 'buyer@example.com',
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.events.0.location', 'Los Angeles, CA')
            ->assertJsonMissingPath('data.shipping_address')
            ->assertJsonMissingPath('data.tracking_number');
    }

    public function test_not_found_and_email_mismatch_have_identical_responses(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        Order::factory()->for($user)->create(['order_number' => 'OQ-PRIVATE']);

        $missing = $this->postJson('/api/v1/orders/track', [
            'order_number' => 'OQ-MISSING',
            'email' => 'owner@example.com',
        ]);
        $mismatch = $this->postJson('/api/v1/orders/track', [
            'order_number' => 'OQ-PRIVATE',
            'email' => 'wrong@example.com',
        ]);

        $this->assertSame(404, $missing->status());
        $this->assertSame($missing->json(), $mismatch->json());
    }

    public function test_tracking_query_is_rate_limited(): void
    {
        RateLimiter::clear('tracking-ip|127.0.0.1');
        $payload = ['order_number' => 'OQ-RATE', 'email' => 'rate@example.com'];
        RateLimiter::clear('tracking-query|'.hash('sha256', 'oq-rate|rate@example.com'));

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/v1/orders/track', $payload)->assertNotFound();
        }

        $this->postJson('/api/v1/orders/track', $payload)->assertTooManyRequests();
    }

    public function test_customer_never_receives_label_or_provider_ids_but_admin_does(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer)->create([
            'tracking_number' => '9400000000000000000000',
            'easypost_shipment_id' => 'shp_private',
            'shipping_rate_id' => 'rate_private',
            'shipping_carrier' => 'USPS',
            'shipping_service' => 'Priority',
            'shipment_status' => 'in_transit',
            'label_url' => 'https://labels.example/private.pdf',
            'stripe_session_id' => 'cs_private',
        ]);

        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.shipment.tracking_number', '9400000000000000000000')
            ->assertJsonMissingPath('data.shipment.label_url')
            ->assertJsonMissingPath('data.label_url')
            ->assertJsonMissingPath('data.easypost_shipment_id')
            ->assertJsonMissingPath('data.stripe_session_id');

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.shipment.label_url', 'https://labels.example/private.pdf')
            ->assertJsonPath('data.easypost_shipment_id', 'shp_private');
    }

    public function test_shipment_is_null_before_a_label_is_purchased(): void
    {
        $customer = User::factory()->create();
        $order = Order::factory()->for($customer)->create(['tracking_number' => null]);

        $this->actingAs($customer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.shipment', null);
    }
}
