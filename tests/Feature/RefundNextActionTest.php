<?php

namespace Tests\Feature;

use App\Jobs\SendAdminAlert;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\StripeCheckoutService;
use App\Support\RefundAdvice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** What the admin page may offer after a card refund fails (RF-00, RF-02 in the proposal). */
class RefundNextActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake([SendAdminAlert::class]);
    }

    #[DataProvider('failureCases')]
    public function test_each_failure_maps_to_one_next_action(?string $code, ?string $message, int $failures, string $action, bool $retryable): void
    {
        $advice = RefundAdvice::forFailure($code, $message, $failures);

        $this->assertSame($action, $advice['next_action']);
        $this->assertSame($retryable, $advice['retryable']);
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: int, 3: string, 4: bool}> */
    public static function failureCases(): array
    {
        return [
            'closed card' => ['expired_or_canceled_card', null, 1, 'refund_manually', false],
            'lost card' => ['lost_or_stolen_card', null, 1, 'refund_manually', false],
            'declined' => ['declined', null, 1, 'refund_manually', false],
            'stripe refuses a second refund' => [null, 'A previous attempt to refund charge ch_1 failed.', 0, 'refund_manually', false],
            'low balance' => ['insufficient_funds', null, 1, 'top_up_then_retry', true],
            'dispute while refunding' => ['charge_for_pending_refund_disputed', null, 1, 'wait_for_dispute', false],
            'dispute on the charge' => ['charge_disputed', null, 0, 'wait_for_dispute', false],
            'already refunded' => ['charge_already_refunded', null, 0, 'investigate', false],
            'amount too large' => ['amount_too_large', null, 0, 'investigate', false],
            'cancelled in the dashboard' => ['merchant_request', null, 1, 'retry', true],
            'unknown, first time' => ['unknown', null, 1, 'retry', true],
            'unknown, second time' => ['unknown', null, 2, 'refund_manually', false],
            'timeout with no code' => [null, 'Stripe is down', 0, 'retry', true],
        ];
    }

    public function test_the_admin_order_carries_next_action_for_a_closed_card(): void
    {
        $order = $this->cancelledOrderWithFailedRefund('expired_or_canceled_card');

        $this->actingAs($this->admin(), 'sanctum')->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'failed')
            ->assertJsonPath('data.refund.retryable', false)
            ->assertJsonPath('data.refund.next_action', 'refund_manually')
            ->assertJsonPath('data.refund.failure_code', 'expired_or_canceled_card');
    }

    public function test_retry_is_refused_when_the_card_route_is_closed(): void
    {
        $order = $this->cancelledOrderWithFailedRefund('expired_or_canceled_card');

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldNotReceive('retrievePaymentIntent');
            $mock->shouldNotReceive('refundOrder');
        });

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertStatus(409)
            ->assertJsonPath('errors.code', 'refund_not_retryable')
            ->assertJsonPath('errors.next_action', 'refund_manually');
    }

    public function test_retry_after_a_cancelled_refund_needs_a_reason(): void
    {
        $order = $this->cancelledOrderWithFailedRefund('merchant_request');

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertStatus(422)
            ->assertJsonPath('errors.reason.0', 'A reason is required.');
    }

    public function test_the_orders_list_and_dashboard_count_refunds_that_need_action(): void
    {
        $needs = $this->cancelledOrderWithFailedRefund('expired_or_canceled_card');
        $this->cancelledOrderWithFailedRefund('charge_for_pending_refund_disputed', 'pi_dispute');
        $this->cancelledOrderWithFailedRefund(null, 'pi_nocode');

        $admin = $this->admin();

        $ids = collect($this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/orders?refund_action=needed')
            ->assertOk()->json('data.data'))->pluck('id');
        $this->assertCount(2, $ids);
        $this->assertTrue($ids->contains($needs->id));

        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('alerts.refunds_needing_action', 2)
            ->assertJsonPath('filters.refunds_needing_action', 'refund_action=needed');
    }

    public function test_a_failed_refund_webhook_emails_the_owner_with_the_next_step(): void
    {
        $this->cancelledOrder('pi_auth', 'pending');

        $this->postSigned([
            'id' => 'evt_closed_card', 'object' => 'event', 'type' => 'refund.failed',
            'data' => ['object' => ['object' => 'refund', 'id' => 're_z', 'payment_intent' => 'pi_auth', 'failure_reason' => 'expired_or_canceled_card']],
        ])->assertOk();

        Queue::assertPushed(SendAdminAlert::class, fn (SendAdminAlert $job) => $job->email
            && str_contains($job->message, 'Zelle or PayPal')
            && str_contains($job->message, 'expired_or_canceled_card'));
    }

    private function cancelledOrderWithFailedRefund(?string $code, string $intentId = 'pi_auth'): Order
    {
        $order = $this->cancelledOrder($intentId, 'failed');
        $order->forceFill([
            'refund_failure_code' => $code,
            'refund_failure_message' => 'Stripe could not complete the refund',
            'refund_failed_at' => now(),
            'refund_failures' => 1,
        ])->saveQuietly();

        return $order->fresh();
    }

    private function cancelledOrder(string $intentId, string $refundStatus): Order
    {
        $product = Product::factory()->create(['stock_qty' => 10, 'in_stock' => true]);
        $order = Order::factory()->for(User::factory()->create())->create(['status' => 'pending_payment', 'total' => 100]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'price' => 50, 'quantity' => 2, 'total' => 100,
        ]);
        $order->update([
            'status' => 'processing', 'payment_status' => 'paid',
            'stripe_payment_intent_id' => $intentId, 'paid_at' => now()->subMinutes(30),
        ]);
        $order->forceFill(['status' => 'cancelled', 'refund_status' => $refundStatus])->saveQuietly();

        return $order->fresh();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));

        return $admin;
    }

    private function postSigned(array $event)
    {
        $payload = json_encode($event, JSON_THROW_ON_ERROR);
        $secret = 'whsec_refund_next_action';
        $timestamp = time();
        $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        config(['services.stripe.webhook_secret' => $secret, 'services.stripe.currency' => 'usd']);

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $payload);
    }
}
