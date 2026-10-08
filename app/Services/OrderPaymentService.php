<?php

namespace App\Services;

use App\Jobs\SendAdminAlert;
use App\Jobs\SettleCancelledOrderPayment;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Moves money for an order after checkout: captures held payments, and
 * returns money for cancelled orders (releasing the hold when it was never
 * captured, refunding when it was). Stripe's PaymentIntent status is the
 * source of truth, so a capture racing a cancellation always settles right.
 */
class OrderPaymentService
{
    public function __construct(private readonly StripeCheckoutService $stripe) {}

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
                $intent = $this->stripe->capturePayment($order);
            }

            if ($intent->status === 'succeeded') {
                DB::transaction(function () use ($order): void {
                    $order->update(['payment_status' => 'paid', 'paid_at' => now()]);
                    Payment::query()->where('order_id', $order->id)->update(['status' => 'completed', 'updated_at' => now()]);
                });

                return true;
            }

            if ($intent->status === 'canceled') {
                // The hold expired or was cancelled outside the app: nothing can be captured.
                $order->update(['payment_status' => 'failed']);
                Log::critical('Stripe authorization was cancelled before capture.', ['order_id' => $order->id]);
                SendAdminAlert::dispatch("URGENT: payment hold for order {$order->order_number} expired or was cancelled before capture. Do not ship it unpaid.");
            }

            return false;
        });
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
    public function queueSettlement(Order $order): void
    {
        $order->forceFill(['refund_status' => 'pending'])->saveQuietly();

        SettleCancelledOrderPayment::dispatch($order->id)->afterCommit();
    }

    /**
     * Return a cancelled order's money and record the result. Returns the
     * resulting refund_status. Throws when Stripe has not settled yet, so the
     * caller (queue job) retries.
     */
    public function settleCancellation(Order $order): string
    {
        return $this->withPaymentLock($order, function () use ($order): string {
            $order->refresh();

            if (! in_array($order->refund_status, ['pending', 'failed'], true)) {
                return $order->refund_status;
            }

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
                        'payment_status' => $order->isAuthorized() ? 'failed' : $order->payment_status,
                    ]);
                    Payment::query()->where('order_id', $order->id)->update(['status' => 'failed', 'updated_at' => now()]);
                });

                return 'released';
            }

            if ($intent->status !== 'succeeded') {
                throw new RuntimeException("PaymentIntent for order {$order->id} is still {$intent->status}.");
            }

            $refund = $this->stripe->refundOrder($order);

            if ($refund->status === 'succeeded') {
                $this->markRefunded($order);

                return 'succeeded';
            }

            if (in_array($refund->status, ['pending', 'requires_action'], true)) {
                // The charge.refunded / refund.failed webhook records the outcome.
                return 'pending';
            }

            throw new RuntimeException("Stripe refund for order {$order->id} ended as {$refund->status}.");
        });
    }

    private function markRefunded(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $order->update([
                'refund_status' => 'succeeded',
                'payment_status' => 'refunded',
                'refunded_amount' => $order->total,
                'refunded_at' => now(),
            ]);
            Payment::query()->where('order_id', $order->id)->update([
                'status' => 'refunded',
                'amount' => $order->total,
                'updated_at' => now(),
            ]);
        });
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
