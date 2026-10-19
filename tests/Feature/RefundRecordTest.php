<?php

namespace Tests\Feature;

use App\Jobs\SendAdminAlert;
use App\Jobs\SettleCancelledOrderPayment;
use App\Mail\OrderRefundedMail;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Payment;
use App\Models\Product;
use App\Models\RefundAttempt;
use App\Models\RefundEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderPaymentService;
use App\Services\RefundService;
use App\Services\StripeCheckoutService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Tests\TestCase;

/** The refund record: attempts, events, the safeguards R1-R10 and the manual route (RF-01 to RF-13). */
class RefundRecordTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake([SendAdminAlert::class]);
        $this->customer = User::factory()->create();
    }

    // ── Card refunds ─────────────────────────────────────────────────────────

    public function test_a_customer_cancel_is_recorded_as_one_attempt_with_its_events(): void
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_1', 'status' => 'succeeded']);

        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();

        $attempt = RefundAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('customer_cancel', $attempt->trigger);
        $this->assertSame('stripe', $attempt->channel);
        $this->assertSame(10000, $attempt->amount_cents);
        $this->assertSame('succeeded', $attempt->status);
        $this->assertSame('re_1', $attempt->stripe_refund_id);
        $this->assertSame("refund-order-{$order->id}", $attempt->stripe_idempotency_key);
        $this->assertSame($this->customer->id, $attempt->created_by);
        $this->assertSame(
            ['requested', 'sent_to_stripe', 'succeeded', 'customer_emailed'],
            RefundEvent::query()->where('order_id', $order->id)->orderBy('id')->pluck('event')->all(),
        );
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'cancelled', 'payment_status' => 'refunded', 'refund_status' => 'succeeded', 'refunded_via' => 'stripe']);
        Mail::assertQueued(OrderRefundedMail::class, fn (OrderRefundedMail $mail) => $mail->order->is($order));
    }

    public function test_accepting_a_cancellation_request_records_who_started_the_refund(): void
    {
        $order = $this->paidOrder(['paid_at' => now()->subDay(), 'authorized_at' => now()->subDay()]);
        $request = OrderCancellationRequest::create([
            'order_id' => $order->id, 'user_id' => $this->customer->id, 'reason' => 'Changed my mind about it.', 'status' => 'pending',
        ]);
        $this->stripeReturns(['id' => 're_req', 'status' => 'succeeded']);
        $admin = $this->staff('Admin');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/cancellation-requests/{$request->id}/accept")->assertOk();

        $attempt = RefundAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('request_accepted', $attempt->trigger);
        $this->assertSame($admin->id, $attempt->created_by);
    }

    public function test_a_closed_card_ends_with_manual_guidance_and_no_retry(): void
    {
        $order = $this->closedCardOrder();
        $admin = $this->staff('Admin');

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'failed')
            ->assertJsonPath('data.refund.retryable', false)
            ->assertJsonPath('data.refund.next_action', 'refund_manually')
            ->assertJsonPath('data.refund.failure_code', 'expired_or_canceled_card')
            ->assertJsonPath('data.refund.amount_paid', '100.00')
            ->assertJsonPath('data.refund.amount_owed', '100.00')
            ->assertJsonPath('data.refund.attempts.0.trigger', 'admin_cancel')
            ->assertJsonPath('data.refund.attempts.0.status', 'failed');

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund")
            ->assertStatus(409)->assertJsonPath('errors.code', 'refund_not_retryable');

        $this->getJson('/api/v1/admin/orders?refund_action=needed')->assertOk()->assertJsonCount(1, 'data.data');
        Queue::assertPushed(SendAdminAlert::class, fn (SendAdminAlert $job) => $job->email && str_contains($job->message, 'Zelle or PayPal'));
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_a_refund_the_bank_refuses_after_it_succeeded_puts_the_payment_back_to_paid(): void
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_late', 'status' => 'succeeded']);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('refunded', $order->fresh()->payment_status);

        $this->refundWebhook('evt_late_fail', 'refund.failed', ['id' => 're_late', 'status' => 'failed', 'failure_reason' => 'lost_or_stolen_card']);

        $order = $order->fresh();
        $this->assertSame('paid', $order->payment_status);
        $this->assertSame('failed', $order->refund_status);
        $this->assertEquals(0, $order->refunded_amount);
        $this->assertNull($order->refunded_at);
        $this->assertSame('failed', RefundAttempt::query()->where('stripe_refund_id', 're_late')->value('status'));
    }

    public function test_a_second_attempt_after_a_failure_gets_a_new_stripe_key(): void
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_a', 'status' => 'pending']);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->refundWebhook('evt_balance', 'refund.failed', ['id' => 're_a', 'status' => 'failed', 'failure_reason' => 'insufficient_funds']);

        $keys = [];
        $this->mock(StripeCheckoutService::class, function ($mock) use (&$keys): void {
            $mock->shouldReceive('retrievePaymentIntent')->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->once()->andReturnUsing(function ($order, $key) use (&$keys) {
                $keys[] = $key;

                return Refund::constructFrom(['id' => 're_b', 'status' => 'succeeded']);
            });
        });

        $this->actingAs($this->staff('Admin'), 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertOk();

        $this->assertSame(["refund-order-{$order->id}-1"], $keys);
        $this->assertSame(['admin_retry'], RefundAttempt::query()->where('attempt_no', 2)->pluck('trigger')->all());
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    // ── Manual refunds (RF-05 to RF-07) ──────────────────────────────────────

    public function test_recording_a_zelle_refund_closes_the_refund_and_refuses_a_second_one(): void
    {
        $order = $this->closedCardOrder();
        $admin = $this->staff('Admin');

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody())
            ->assertCreated()
            ->assertJsonPath('data.attempt.channel', 'manual')
            ->assertJsonPath('data.attempt.method', 'zelle')
            ->assertJsonPath('data.attempt.external_reference', '8F3K29QX')
            ->assertJsonPath('data.order.refund.status', 'succeeded')
            ->assertJsonPath('data.order.refund.refunded_via', 'manual')
            ->assertJsonPath('data.order.refund.amount_owed', '0.00')
            ->assertJsonPath('data.order.refund.next_action', 'none');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'refunded', 'refund_status' => 'succeeded', 'status' => 'cancelled']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'refunded']);
        Mail::assertQueued(OrderRefundedMail::class, 1);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertStatus(409);
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody())
            ->assertStatus(409)->assertJsonPath('errors.code', 'nothing_owed');
    }

    public function test_a_manual_refund_larger_than_what_is_owed_is_refused(): void
    {
        $order = $this->closedCardOrder();

        $this->actingAs($this->staff('Admin'), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody(['amount' => 100.01]))
            ->assertStatus(422)
            ->assertJsonPath('errors.code', 'amount_exceeds_owed');

        $this->assertSame(0, RefundAttempt::query()->where('channel', 'manual')->count());
    }

    public function test_manual_refund_details_are_validated(): void
    {
        $order = $this->closedCardOrder();

        $response = $this->actingAs($this->staff('Admin'), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", ['amount' => 10, 'method' => 'cash']);
        $response->assertStatus(422);
        $this->assertArrayHasKey('method', $response->json('errors'));
        $this->assertArrayHasKey('external_reference', $response->json('errors'));
    }

    public function test_voiding_a_manual_refund_keeps_the_history_and_raises_the_amount_owed_again(): void
    {
        $order = $this->closedCardOrder();
        $this->actingAs($this->staff('Admin'), 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody())->assertCreated();
        $manual = RefundAttempt::query()->where('channel', 'manual')->sole();
        $stripe = RefundAttempt::query()->where('channel', 'stripe')->sole();

        // Only the owner account may void.
        $this->actingAs($this->staff('Admin'), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund/attempts/{$manual->id}/void", ['reason' => 'Wrong amount'])
            ->assertForbidden();

        $owner = $this->staff('Owner');
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund/attempts/{$manual->id}/void", [])
            ->assertStatus(422);
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund/attempts/{$stripe->id}/void", ['reason' => 'Nope'])
            ->assertStatus(409)->assertJsonPath('errors.code', 'not_voidable');

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund/attempts/{$manual->id}/void", ['reason' => 'Sent to the wrong person'])
            ->assertOk()
            ->assertJsonPath('data.attempt.status', 'voided')
            ->assertJsonPath('data.order.refund.amount_owed', '100.00')
            ->assertJsonPath('data.order.refund.status', 'failed')
            ->assertJsonPath('data.order.refund.next_action', 'refund_manually');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'payment_status' => 'paid', 'refunded_via' => null]);
        // The original entry and its events are still there.
        $this->assertSame('voided', $manual->fresh()->status);
        $this->assertSame(['manual_recorded', 'voided'], $manual->events()->pluck('event')->all());
        $this->assertSame('Sent to the wrong person', $manual->events()->where('event', 'voided')->first()->details['reason']);

        // After a void a new manual refund can be recorded.
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody(['external_reference' => 'NEWREF']))->assertCreated();
    }

    public function test_a_manual_refund_while_a_retry_is_still_offered_needs_a_written_reason(): void
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_low', 'status' => 'pending']);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->refundWebhook('evt_low', 'refund.failed', ['id' => 're_low', 'status' => 'failed', 'failure_reason' => 'insufficient_funds']);

        $admin = $this->staff('Admin');
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody())
            ->assertStatus(409)->assertJsonPath('errors.code', 'retry_available');

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody(['override_reason' => 'Customer cannot wait']))
            ->assertCreated();
        $this->assertSame('Customer cannot wait', RefundEvent::query()->where('event', 'manual_recorded')->first()->details['override_reason']);
    }

    // ── Safeguards (RF-08 to RF-10) ──────────────────────────────────────────

    public function test_a_refund_in_progress_blocks_another_one_and_a_repeated_click_gets_the_first_answer(): void
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_slow', 'status' => 'pending']);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('pending', $order->fresh()->refund_status);

        $admin = $this->staff('Admin');
        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/refund", [], ['Idempotency-Key' => 'click-1'])
            ->assertStatus(409)->assertJsonPath('errors.code', 'refund_in_progress');
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", [], ['Idempotency-Key' => 'click-1'])
            ->assertStatus(409)->assertHeader('Idempotent-Replay', 'true');

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody())
            ->assertStatus(409)->assertJsonPath('errors.code', 'refund_in_progress');

        $this->assertSame(1, RefundAttempt::query()->where('order_id', $order->id)->count());
    }

    public function test_the_database_allows_only_one_open_refund_per_order(): void
    {
        $order = $this->paidOrder();
        $make = fn (int $no) => RefundAttempt::create([
            'order_id' => $order->id, 'attempt_no' => $no, 'trigger' => 'system', 'channel' => 'stripe',
            'amount_cents' => 100, 'status' => 'pending',
        ]);
        $make(1);

        $this->expectException(QueryException::class);
        $make(2);
    }

    public function test_a_stripe_timeout_is_retried_on_the_same_attempt_with_the_same_key(): void
    {
        $order = $this->paidOrder(['status' => 'cancelled']);
        $keys = [];
        $calls = 0;
        $this->mock(StripeCheckoutService::class, function ($mock) use (&$keys, &$calls): void {
            $mock->shouldReceive('refundOrder')->twice()->andReturnUsing(function ($order, $key) use (&$keys, &$calls) {
                $keys[] = $key;
                if (++$calls === 1) {
                    throw new ApiConnectionException('Could not connect to Stripe.');
                }

                return Refund::constructFrom(['id' => 're_retry_same', 'status' => 'succeeded']);
            });
        });
        $refunds = app(RefundService::class);

        try {
            $refunds->sendToStripe($order, 'admin_cancel');
            $this->fail('The timeout should reach the caller so the queue retries.');
        } catch (ApiConnectionException) {
        }

        $attempt = RefundAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('requested', $attempt->status);
        $this->assertSame('auto_retry', $attempt->next_action);
        $this->assertNotNull($attempt->next_retry_at);

        $this->assertSame('succeeded', $refunds->sendToStripe($order, 'admin_cancel'));

        $this->assertSame(["refund-order-{$order->id}", "refund-order-{$order->id}"], $keys);
        $this->assertSame(1, RefundAttempt::query()->where('order_id', $order->id)->count());
        $this->assertContains('retry_scheduled', $attempt->events()->pluck('event')->all());
        $this->assertSame('succeeded', $attempt->fresh()->status);
    }

    public function test_the_admin_sees_auto_retry_while_the_shop_keeps_trying(): void
    {
        $order = $this->paidOrder(['status' => 'cancelled', 'refund_status' => 'pending']);
        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->andThrow(new ApiConnectionException('Could not connect to Stripe.'));
        });

        try {
            app(OrderPaymentService::class)->settleCancellation($order, 'admin_cancel');
        } catch (ApiConnectionException) {
        }

        $this->actingAs($this->staff('Admin'), 'sanctum')->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.refund.status', 'pending')
            ->assertJsonPath('data.refund.next_action', 'auto_retry')
            ->assertJsonPath('data.refund.retryable', false);
    }

    public function test_when_the_queue_gives_up_the_admin_retries_the_same_attempt_with_the_same_key(): void
    {
        $order = $this->paidOrder(['status' => 'cancelled', 'refund_status' => 'pending']);
        $keys = [];
        $this->mock(StripeCheckoutService::class, function ($mock) use (&$keys): void {
            $mock->shouldReceive('retrievePaymentIntent')->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->twice()->andReturnUsing(function ($order, $key) use (&$keys) {
                $keys[] = $key;
                if (count($keys) === 1) {
                    throw new ApiConnectionException('Could not connect to Stripe.');
                }

                return Refund::constructFrom(['id' => 're_admin_retry', 'status' => 'succeeded']);
            });
        });

        $job = new SettleCancelledOrderPayment($order->id, 'admin_cancel');
        try {
            $job->handle(app(OrderPaymentService::class));
            $this->fail('The outage should reach the queue.');
        } catch (ApiConnectionException $e) {
            $job->failed($e);
        }

        $admin = $this->staff('Admin');
        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/orders/{$order->id}")
            ->assertJsonPath('data.refund.status', 'failed')
            ->assertJsonPath('data.refund.next_action', 'retry')
            ->assertJsonPath('data.refund.retryable', true);

        $this->postJson("/api/v1/admin/orders/{$order->id}/refund")->assertOk();

        $this->assertSame(["refund-order-{$order->id}", "refund-order-{$order->id}"], $keys);
        $this->assertSame(1, RefundAttempt::query()->where('order_id', $order->id)->count());
        $this->assertSame('succeeded', RefundAttempt::query()->where('order_id', $order->id)->value('status'));
        $this->assertSame('refunded', $order->fresh()->payment_status);
    }

    public function test_a_refusal_stripe_will_repeat_closes_the_attempt_and_the_queue_does_not_retry(): void
    {
        $order = $this->paidOrder(['status' => 'cancelled', 'refund_status' => 'pending']);
        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrievePaymentIntent')->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->once()->andThrow(
                InvalidRequestException::factory('A previous attempt to refund charge ch_1 failed.', 400)
            );
        });

        $job = new SettleCancelledOrderPayment($order->id, 'admin_cancel');
        $job->handle(app(OrderPaymentService::class));

        $attempt = RefundAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('failed', $attempt->status);
        $this->assertSame('refund_manually', $attempt->next_action);
        $this->assertSame('failed', $order->fresh()->refund_status);
        Queue::assertPushed(SendAdminAlert::class, fn (SendAdminAlert $alert) => $alert->email && str_contains($alert->message, 'Zelle or PayPal'));
    }

    public function test_a_webhook_delivered_twice_is_recorded_once_and_alerts_once(): void
    {
        $order = $this->closedCardOrder();
        $before = RefundEvent::query()->where('order_id', $order->id)->count();

        $this->refundWebhook('evt_dup', 'refund.failed', ['id' => 're_closed', 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card']);
        $this->refundWebhook('evt_dup', 'refund.failed', ['id' => 're_closed', 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card']);

        $this->assertSame(1, RefundEvent::query()->where('stripe_event_id', 'evt_dup')->count());
        $this->assertSame($before + 1, RefundEvent::query()->where('order_id', $order->id)->count());
        // Only the first failure alerted the owner.
        Queue::assertPushed(SendAdminAlert::class, 1);
        $this->assertSame('failed', $order->fresh()->refund_status);
    }

    public function test_a_late_pending_update_cannot_undo_a_succeeded_refund(): void
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_order', 'status' => 'succeeded']);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();

        $this->refundWebhook('evt_late_pending', 'refund.updated', ['id' => 're_order', 'status' => 'pending']);

        $this->assertSame('succeeded', RefundAttempt::query()->where('stripe_refund_id', 're_order')->value('status'));
        $this->assertSame('refunded', $order->fresh()->payment_status);
        $this->assertContains('ignored', RefundEvent::query()->where('order_id', $order->id)->pluck('event')->all());
    }

    public function test_refund_events_cannot_be_changed_or_deleted(): void
    {
        $order = $this->paidOrder();
        $event = RefundEvent::create(['order_id' => $order->id, 'event' => 'requested', 'actor' => 'system']);

        try {
            $event->update(['event' => 'succeeded']);
            $this->fail('An event was changed.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $event->delete();
    }

    // ── Reconciliation (RF-11) ───────────────────────────────────────────────

    public function test_a_refund_made_in_the_stripe_dashboard_is_added_by_the_nightly_check(): void
    {
        $order = $this->paidOrder();
        $dashboard = Refund::constructFrom([
            'id' => 're_dashboard', 'status' => 'succeeded', 'amount' => 10000, 'currency' => 'usd', 'payment_intent' => 'pi_auth',
        ]);
        $foreign = Refund::constructFrom(['id' => 're_other_shop', 'status' => 'succeeded', 'amount' => 500, 'payment_intent' => 'pi_not_ours']);
        $this->mock(StripeCheckoutService::class, fn ($mock) => $mock->shouldReceive('listRefundsSince')->andReturn([$dashboard, $foreign]));

        $this->artisan('refunds:reconcile')->assertSuccessful();

        $attempt = RefundAttempt::query()->where('order_id', $order->id)->sole();
        $this->assertSame('reconciliation', $attempt->channel);
        $this->assertSame('re_dashboard', $attempt->stripe_refund_id);
        $this->assertSame('refunded', $order->fresh()->payment_status);
        Queue::assertPushed(SendAdminAlert::class, fn (SendAdminAlert $job) => $job->email && str_contains($job->message, 're_dashboard'));

        // A second run finds nothing new and writes nothing.
        $events = RefundEvent::query()->count();
        $this->artisan('refunds:reconcile')->assertSuccessful();
        $this->assertSame($events, RefundEvent::query()->count());
        $this->assertSame(1, RefundAttempt::query()->count());
    }

    public function test_the_nightly_check_catches_up_a_status_the_webhooks_missed(): void
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_missed', 'status' => 'pending']);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('pending', $order->fresh()->refund_status);

        $now = Refund::constructFrom(['id' => 're_missed', 'status' => 'succeeded', 'amount' => 10000, 'payment_intent' => 'pi_auth']);
        $this->mock(StripeCheckoutService::class, fn ($mock) => $mock->shouldReceive('listRefundsSince')->andReturn([$now]));

        $this->artisan('refunds:reconcile')->assertSuccessful();

        $this->assertSame('succeeded', $order->fresh()->refund_status);
        $this->assertSame(1, RefundAttempt::query()->where('order_id', $order->id)->count());
    }

    public function test_a_refund_pending_for_more_than_five_business_days_is_flagged_once(): void
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_stuck', 'status' => 'pending']);
        $this->actingAs($this->customer, 'sanctum')->postJson("/api/v1/orders/{$order->id}/cancel")->assertOk();
        RefundAttempt::query()->where('order_id', $order->id)->update(['created_at' => now()->subDays(9)]);

        $this->assertSame(1, app(RefundService::class)->alertStalePending());
        $this->assertSame(0, app(RefundService::class)->alertStalePending());
        Queue::assertPushed(SendAdminAlert::class, fn (SendAdminAlert $job) => $job->email && str_contains($job->message, 're_stuck'));
    }

    // ── Importing the pre-launch log (RF-12) ─────────────────────────────────

    public function test_the_refund_log_is_imported_once_as_manual_entries(): void
    {
        $order = $this->closedCardOrder();
        $second = $this->paidOrder(['stripe_payment_intent_id' => 'pi_two', 'status' => 'cancelled', 'refund_status' => 'failed', 'total' => 40]);
        $this->staff('Owner');
        $header = "Line\tDate found\tOrder number\tAmount (USD)\tStripe failure reason\tCustomer emailed on\tMethod (Zelle/PayPal)\tReference number\tDate sent\tSent by\tOrder updated by developer on\tCorrects line\tImported to record on";
        $rows = [
            "1\t2026-10-14\t{$order->order_number}\t100.00\texpired_or_canceled_card\t2026-10-14\tZelle\tREF1\t2026-10-15\tOwner\t\t\t",
            "2\t2026-10-14\t{$second->order_number}\t40.00\tdeclined\t2026-10-14\tPayPal\tWRONG\t2026-10-16\tOwner\t\t\t",
            "3\t2026-10-17\t{$second->order_number}\t40.00\t\t\tPayPal\tRIGHT\t2026-10-16\tOwner\t\t2\t",
        ];
        $path = sys_get_temp_dir().'/refund-log-'.uniqid().'.tsv';
        file_put_contents($path, implode("\n", [$header, ...$rows])."\n");

        $this->artisan('refunds:import-log', ['file' => $path, '--dry-run' => true])->assertSuccessful();
        $this->assertSame(0, RefundAttempt::query()->where('channel', 'manual')->count());

        $this->artisan('refunds:import-log', ['file' => $path])->assertSuccessful();

        $first = RefundAttempt::query()->where('order_id', $order->id)->where('channel', 'manual')->sole();
        $this->assertSame('recorded', $first->status);
        $this->assertSame('REF1', $first->external_reference);
        $this->assertSame(1, RefundEvent::query()->where('attempt_id', $first->id)->where('event', 'imported_from_log')->first()->details['log_line']);
        $this->assertSame('refunded', $order->fresh()->payment_status);

        $wrong = RefundAttempt::query()->where('external_reference', 'WRONG')->sole();
        $right = RefundAttempt::query()->where('external_reference', 'RIGHT')->sole();
        $this->assertSame('voided', $wrong->status);
        $this->assertSame('recorded', $right->status);
        $this->assertSame('refunded', $second->fresh()->payment_status);
        // The customers were told when the refunds were sent, not by the import.
        Mail::assertNotQueued(OrderRefundedMail::class);

        $this->artisan('refunds:import-log', ['file' => $path])->assertSuccessful();
        $this->assertSame(3, RefundAttempt::query()->where('channel', 'manual')->count());

        unlink($path);
    }

    // ── What the customer sees (RF-13) ───────────────────────────────────────

    public function test_the_customer_sees_how_the_money_came_back_but_never_codes_or_notes(): void
    {
        $order = $this->closedCardOrder();
        $this->actingAs($this->staff('Admin'), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/refund/manual", $this->manualBody(['note' => 'Customer asked for Zelle']))->assertCreated();

        $response = $this->actingAs($this->customer, 'sanctum')->getJson("/api/v1/orders/{$order->id}")->assertOk()
            ->assertJsonPath('data.refund.status', 'succeeded')
            ->assertJsonPath('data.refund.refunded_via', 'manual')
            ->assertJsonPath('data.refund.method', 'zelle');

        $json = json_encode($response->json());
        $this->assertStringNotContainsString('expired_or_canceled_card', $json);
        $this->assertStringNotContainsString('8F3K29QX', $json);
        $this->assertStringNotContainsString('Customer asked for Zelle', $json);
        $this->assertArrayNotHasKey('attempts', $response->json('data.refund'));
        $this->assertArrayNotHasKey('next_action', $response->json('data.refund'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** A captured order of $100 inside the customer's cancel window. */
    private function paidOrder(array $attributes = []): Order
    {
        $product = Product::factory()->create(['stock_qty' => 10, 'in_stock' => true]);
        $order = Order::factory()->for($this->customer)->create(['status' => 'pending_payment', 'total' => 100]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku,
            'price' => 50, 'quantity' => 2, 'total' => 100,
        ]);
        $order->update(array_merge([
            'status' => 'processing', 'payment_status' => 'paid',
            'stripe_payment_intent_id' => 'pi_auth', 'authorized_at' => now()->subMinutes(30), 'paid_at' => now()->subMinutes(30),
        ], $attributes));
        Payment::create([
            'order_id' => $order->id, 'transaction_id' => 'pi_auth', 'payment_provider' => 'stripe', 'status' => 'completed', 'amount' => $order->total,
        ]);

        return $order->fresh();
    }

    /** An admin cancels a paid order, Stripe accepts the refund, and the bank refuses it: order 70 in the proposal. */
    private function closedCardOrder(): Order
    {
        $order = $this->paidOrder();
        $this->stripeReturns(['id' => 're_closed', 'status' => 'pending']);

        $this->actingAs($this->staff('Admin'), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/status", ['status' => 'cancelled'])->assertOk();
        $this->refundWebhook('evt_closed', 'refund.failed', ['id' => 're_closed', 'status' => 'failed', 'failure_reason' => 'expired_or_canceled_card']);

        return $order->fresh();
    }

    /** @param  array<string, mixed>  $refund */
    private function stripeReturns(array $refund): void
    {
        $this->mock(StripeCheckoutService::class, function ($mock) use ($refund): void {
            $mock->shouldReceive('retrievePaymentIntent')->andReturn($this->intent('succeeded'));
            $mock->shouldReceive('refundOrder')->once()->andReturn(Refund::constructFrom($refund));
        });
    }

    /** @return array<string, mixed> */
    private function manualBody(array $overrides = []): array
    {
        return array_merge([
            'amount' => 100.00, 'method' => 'zelle', 'external_reference' => '8F3K29QX',
            'sent_at' => now()->subHour()->toIso8601String(),
        ], $overrides);
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => $role]));

        return $user;
    }

    private function intent(string $status): PaymentIntent
    {
        return PaymentIntent::constructFrom(['id' => 'pi_auth', 'status' => $status]);
    }

    /** @param  array<string, mixed>  $refund */
    private function refundWebhook(string $eventId, string $type, array $refund)
    {
        $payload = json_encode([
            'id' => $eventId, 'object' => 'event', 'type' => $type,
            'data' => ['object' => ['object' => 'refund', 'payment_intent' => 'pi_auth', 'amount' => 10000] + $refund],
        ], JSON_THROW_ON_ERROR);
        $secret = 'whsec_refund_record_test';
        $timestamp = time();
        $signature = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);
        config(['services.stripe.webhook_secret' => $secret, 'services.stripe.currency' => 'usd']);

        return $this->call('POST', '/api/v1/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], $payload)->assertOk();
    }
}
