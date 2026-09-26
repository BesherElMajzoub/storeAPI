<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class AdminOrderBulkStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::create(['name' => 'Admin']));
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_bulk_order_status_update_is_atomic_and_returns_a_result_per_id(): void
    {
        $pending = Order::factory()->create(['status' => 'pending']);
        $processing = Order::factory()->create(['status' => 'processing']);

        $response = $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$pending->id, $processing->id],
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertEqualsCanonicalizing(
            [$pending->id, $processing->id],
            collect($response->json('data'))->pluck('id')->all()
        );
        $response->assertJsonPath('data.0.status', 'updated');
        $this->assertSame('cancelled', $pending->fresh()->status);
        $this->assertSame('cancelled', $processing->fresh()->status);
    }

    public function test_one_invalid_transition_rejects_the_entire_bulk_order_update(): void
    {
        $processing = Order::factory()->create(['status' => 'processing']);
        $pendingPayment = Order::factory()->create(['status' => 'pending_payment']);

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$processing->id, $pendingPayment->id],
            'status' => 'shipped',
        ])->assertConflict()
            ->assertJsonPath('errors.orders.'.$pendingPayment->id.'.0', 'Cannot transition order from pending_payment to shipped.');

        $this->assertSame('processing', $processing->fresh()->status);
        $this->assertSame('pending_payment', $pendingPayment->fresh()->status);
    }

    public function test_bulk_order_status_validation_rejects_duplicates_and_soft_deleted_orders(): void
    {
        $order = Order::factory()->create();

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$order->id, $order->id],
            'status' => 'cancelled',
        ])->assertUnprocessable()->assertJsonValidationErrors('ids.1');

        $order->delete();

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$order->id],
            'status' => 'cancelled',
        ])->assertUnprocessable()->assertJsonValidationErrors('ids.0');
    }

    public function test_bulk_cancellation_expires_every_open_checkout_session_before_updating_orders(): void
    {
        $first = Order::factory()->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_bulk_first',
        ]);
        $second = Order::factory()->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_bulk_second',
        ]);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            foreach (['cs_bulk_first', 'cs_bulk_second'] as $sessionId) {
                $mock->shouldReceive('retrieveCheckoutSession')->once()->with($sessionId)
                    ->andReturn(StripeSession::constructFrom(['id' => $sessionId, 'status' => 'open']));
                $mock->shouldReceive('expireCheckoutSession')->once()->with($sessionId)
                    ->andReturn(StripeSession::constructFrom(['id' => $sessionId, 'status' => 'expired']));
            }
        });

        $this->postJson('/api/v1/admin/orders/bulk-status', [
            'ids' => [$first->id, $second->id],
            'status' => 'cancelled',
        ])->assertOk();

        $this->assertSame('cancelled', $first->fresh()->status);
        $this->assertSame('cancelled', $second->fresh()->status);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
