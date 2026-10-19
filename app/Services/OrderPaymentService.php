<?php

namespace App\Services;

use App\Exceptions\RefundRefused;
use App\Jobs\SendAdminAlert;
use App\Jobs\SettleCancelledOrderPayment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * Moves money for an order after checkout: captures held payments, and
 * returns money for cancelled orders (releasing the hold when it was never
 * captured, refunding when it was). Stripe's PaymentIntent status is the
 * source of truth, so a capture racing a cancellation always settles right.
 */
class OrderPaymentService
{
    /** PaymentIntent states in which a held payment can never be captured. */
    private const UNCAPTURABLE_INTENT_STATUSES = ['canceled', 'requires_payment_method'];

    public function __construct(
        private readonly StripeCheckoutService $stripe,
        private readonly RefundService $refunds,
    ) {}

    /**
     * Capture an authorized payment. Returns true when the order ends up paid.
     */
    public function capture(Order $order): bool
    {
        return $this->withPaymentLock($order, function () use ($order): bool {
            $order->refresh();

            if (! $order->isAuthorized() || $order->status === 'cancelled') {
                return $order->isPaid();
            }

            $intent = $this->stripe->retrievePaymentIntent($order);

            if ($intent->status === 'requires_capture') {
                try {
                    $intent = $this->stripe->capturePayment($order);
                } catch (ApiErrorException $e) {
                    // A declined or expired hold leaves the intent in a final
                    // state; anything else (network, Stripe outage) is retried.
                    $intent = $this->stripe->retrievePaymentIntent($order);
                    if (! in_array($intent->status, self::UNCAPTURABLE_INTENT_STATUSES, true) && $intent->status !== 'succeeded') {
                        throw $e;
                    }
                }
            }

            if ($intent->status === 'succeeded') {
                DB::transaction(function () use ($order): void {
                    $order->update(['payment_status' => 'paid', 'paid_at' => now(), 'capture_attempts' => 0]);
                    Payment::query()->where('order_id', $order->id)->update(['status' => 'completed', 'updated_at' => now()]);
                });

                return true;
            }

            if (in_array($intent->status, self::UNCAPTURABLE_INTENT_STATUSES, true)) {
                $this->markCaptureFailed($order, "Stripe PaymentIntent is {$intent->status}");
            }

            return false;
        });
    }

    /**
     * A held payment can no longer be captured (hold expired, card blocked,
     * or cancelled in Stripe). The order stays in processing on a fulfilment
     * hold so it is never shipped unpaid; the admin contacts the customer or
     * cancels it, which restocks the items.
     */
    public function markCaptureFailed(Order $order, string $reason): void
    {
        $updated = Order::query()->whereKey($order->id)
            ->where('status', 'processing')->where('payment_status', 'authorized')
            ->update([
                'payment_status' => 'failed',
                'fulfillment_hold' => true,
                'capture_failed_at' => now(),
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return;
        }

        Payment::query()->where('order_id', $order->id)->update(['status' => 'failed', 'updated_at' => now()]);
        $order->refresh();

        Log::critical('Held payment could not be captured.', ['order_id' => $order->id, 'reason' => $reason]);
        SendAdminAlert::dispatch(
            "URGENT: the card payment for order {$order->order_number} could not be captured ({$reason}). "
            .'The order is on hold: do not ship it. Contact the customer, or cancel the order to restock it.',
            email: true,
        );
    }

    /**
     * Count a capture attempt that failed for a transient reason; the admin
     * is alerted once when it keeps failing. The scheduler keeps retrying.
     */
    public function recordCaptureError(Order $order, Throwable $e): void
    {
        $attempts = (int) $order->capture_attempts + 1;
        Order::query()->whereKey($order->id)->update(['capture_attempts' => $attempts]);

        Log::warning('Unable to capture authorized payment; will retry.', [
            'order_id' => $order->id,
            'attempt' => $attempts,
            'error' => $e->getMessage(),
        ]);

        if ($attempts === max(1, (int) config('orders.capture_max_attempts', 3))) {
            SendAdminAlert::dispatch(
                "Capturing the card payment for order {$order->order_number} has failed {$attempts} times "
                ."({$e->getMessage()}). It will keep retrying; check Stripe if this continues.",
                email: true,
            );
        }
    }

    /**
     * Close an unpaid order's Checkout Session so a tab left open cannot pay
     * for it after it is cancelled. Returns false when the customer already
     * completed it (the payment webhook is on its way).
     */
    public function expireOpenCheckout(Order $order): bool
    {
        if (! $order->stripe_session_id) {
            return true;
        }

        $session = $this->stripe->retrieveCheckoutSession($order->stripe_session_id);

        if ($session->status === 'complete') {
            return false;
        }

        if ($session->status === 'open') {
            $this->stripe->expireCheckoutSession($order->stripe_session_id);
        }

        return true;
    }

    /**
     * Mark a cancelled order's money as owed back and queue the Stripe call.
     * The order stays cancelled whatever Stripe does; retries live in the job.
     */
    public function queueSettlement(Order $order, string $trigger = 'system', ?int $actorId = null): void
    {
        $order->forceFill([
            'refund_status' => 'pending',
            'refund_failure_code' => null,
            'refund_failure_message' => null,
            'refund_failed_at' => null,
        ])->saveQuietly();

        try {
            SettleCancelledOrderPayment::dispatch($order->id, $trigger, $actorId)->afterCommit();
        } catch (Throwable $e) {
            $this->recordRefundFailure($order, $e);
            Log::critical('Unable to queue settlement for a cancelled order.', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return a cancelled order's money and record the result. Returns the
     * resulting refund_status. Throws when Stripe has not settled yet, so the
     * caller (queue job) retries.
     */
    public function settleCancellation(Order $order, string $trigger = 'system', ?User $actor = null, ?string $reason = null): string
    {
        return $this->withPaymentLock($order, function () use ($order, $trigger, $actor, $reason): string {
            $order->refresh();

            if (! in_array($order->refund_status, ['pending', 'failed'], true)) {
                return $order->refund_status;
            }

            $order->forceFill([
                'refund_status' => 'pending',
                'refund_failure_code' => null,
                'refund_failure_message' => null,
                'refund_failed_at' => null,
            ])->saveQuietly();

            if (! $order->stripe_payment_intent_id) {
                $order->update(['refund_status' => 'none']);

                return 'none';
            }

            $intent = $this->stripe->retrievePaymentIntent($order);

            if (in_array($intent->status, ['requires_capture', 'requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                $intent = $this->stripe->releaseAuthorization($order);
            }

            if ($intent->status === 'canceled') {
                DB::transaction(function () use ($order): void {
                    $order->update([
                        'refund_status' => 'released',
                        'payment_status' => in_array($order->payment_status, ['authorized', 'failed'], true) ? 'voided' : $order->payment_status,
                    ]);
                    Payment::query()->where('order_id', $order->id)->update(['status' => 'failed', 'updated_at' => now()]);
                });

                return 'released';
            }

            if ($intent->status !== 'succeeded') {
                throw new RuntimeException("PaymentIntent for order {$order->id} is still {$intent->status}.");
            }

            // A pending refund is settled by the refund.updated / refund.failed webhook.
            return $this->refunds->sendToStripe($order, $trigger, $actor, $reason);
        });
    }

    /**
     * Refund a paid order that is not cancelled (an admin's return refund).
     * Returns 'succeeded' or 'pending'.
     */
    public function refundPaidOrder(Order $order, string $trigger, ?User $actor = null, ?string $reason = null): string
    {
        return $this->withPaymentLock($order, fn (): string => $this->refunds->sendToStripe($order, $trigger, $actor, $reason));
    }

    /** @return array{code: ?string, message: string} */
    public function recordRefundFailure(Order $order, Throwable $exception): array
    {
        // The refund service already closed the attempt and recalculated the order.
        if ($exception instanceof RefundRefused) {
            return ['code' => $exception->failureCode, 'message' => $exception->getMessage()];
        }

        $this->refunds->markRetryable($order, $exception);

        $code = $exception instanceof ApiErrorException ? $exception->getStripeCode() : null;
        $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?? '');
        $message = mb_substr($message !== '' ? $message : 'Payment provider error.', 0, 1000);

        $order->forceFill([
            'refund_status' => 'failed',
            'refund_failure_code' => $code,
            'refund_failure_message' => $message,
            'refund_failed_at' => now(),
        ])->saveQuietly();

        return ['code' => $code, 'message' => $message];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withPaymentLock(Order $order, callable $callback): mixed
    {
        return Cache::lock("order:{$order->id}:payment", 60)->block(10, $callback);
    }
}
