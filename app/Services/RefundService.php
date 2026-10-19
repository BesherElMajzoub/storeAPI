<?php

namespace App\Services;

use App\Exceptions\RefundInProgress;
use App\Exceptions\RefundNotAllowed;
use App\Exceptions\RefundRefused;
use App\Jobs\SendAdminAlert;
use App\Jobs\SettleCancelledOrderPayment;
use App\Mail\OrderRefundedMail;
use App\Models\Order;
use App\Models\Payment;
use App\Models\RefundAttempt;
use App\Models\RefundEvent;
use App\Models\User;
use App\Support\RefundAdvice;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Throwable;

/**
 * The only code that returns money for an order (rule R10): the customer's
 * own cancel, an accepted cancellation request, an admin cancel, an admin
 * retry, a refund sent by Zelle or PayPal and a refund found in Stripe all go
 * through here, and each step is written to refund_attempts / refund_events.
 *
 * The order's refund_status, payment_status, refunded_amount and refunded_via
 * are calculated from the attempts by syncOrder() and written nowhere else
 * (rule R7), apart from a failure that happens before any attempt exists.
 */
class RefundService
{
    /** Attempt statuses that cover part of what was paid: money returned or on its way. */
    private const COVERING = [RefundAttempt::REQUESTED, RefundAttempt::PENDING, RefundAttempt::SUCCEEDED, RefundAttempt::RECORDED];

    /** Set while importing the pre-launch log: those customers were already told. */
    private bool $quiet = false;

    public function __construct(private readonly StripeCheckoutService $stripe) {}

    // ── Amounts (R1, R9) ─────────────────────────────────────────────────────

    /** Cents still to be returned: paid minus every succeeded, recorded and in-progress refund. */
    public function owedCents(Order $order): int
    {
        return $this->owedFrom($order, RefundAttempt::query()->where('order_id', $order->id)->get());
    }

    /** @param  Collection<int, RefundAttempt>  $attempts */
    public function owedFrom(Order $order, Collection $attempts): int
    {
        return max(0, $order->totalCents() - (int) $attempts->whereIn('status', self::COVERING)->sum('amount_cents'));
    }

    // ── Stripe refunds ───────────────────────────────────────────────────────

    /**
     * Return the amount still owed through Stripe. Starts a new attempt, or
     * resumes the open one that never got an answer, with the same Stripe key
     * so Stripe cannot create the refund twice. A refund already pending at
     * Stripe is never sent again. Returns 'succeeded' or 'pending'.
     *
     * @throws RefundNotAllowed when nothing is owed or another refund is running
     * @throws RefundRefused when Stripe or the bank refused it for good
     * @throws Throwable when Stripe could not be reached (the caller retries)
     */
    public function sendToStripe(Order $order, string $trigger, ?User $actor = null, ?string $reason = null): string
    {
        $attempt = $this->claimAttempt($order, $trigger, $actor, $reason);

        if ($attempt->status === RefundAttempt::PENDING) {
            return 'pending';
        }

        $this->event($attempt, 'sent_to_stripe');

        try {
            $refund = $this->stripe->refundOrder(
                $order,
                $attempt->stripe_idempotency_key,
                $attempt->amount_cents < $order->totalCents() ? $attempt->amount_cents : null,
            );
        } catch (Throwable $e) {
            $this->handleStripeError($order, $attempt, $e);
        }

        $refundStatus = (string) ($refund->status ?? '');
        $failureReason = $refund->failure_reason ?? null;

        $this->applyStripeStatus($order, $attempt->id, $refundStatus, $refund->id ?? null, $failureReason, 'system', null);

        $attempt->refresh();

        if ($attempt->status === RefundAttempt::FAILED) {
            throw new RefundRefused($attempt->failure_code, (string) $attempt->failure_message, (string) $attempt->next_action);
        }

        if ($attempt->status === RefundAttempt::REQUESTED) {
            throw new \RuntimeException("Stripe refund for order {$order->id} ended as {$refundStatus}.");
        }

        return $attempt->status === RefundAttempt::SUCCEEDED ? 'succeeded' : 'pending';
    }

    private function claimAttempt(Order $order, string $trigger, ?User $actor, ?string $reason): RefundAttempt
    {
        try {
            return DB::transaction(function () use ($order, $trigger, $actor, $reason): RefundAttempt {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $attempts = RefundAttempt::query()->where('order_id', $locked->id)->get();

                $open = $attempts->first(fn (RefundAttempt $a) => $a->isOpen() && ! $a->isManual());
                if ($open) {
                    if ($reason !== null && $open->status === RefundAttempt::REQUESTED) {
                        $this->event($open, 'retry_requested', $this->actor($actor, $trigger), ['reason' => $reason]);
                    }

                    return $open;
                }

                $owed = $this->owedFrom($locked, $attempts);
                if ($owed <= 0) {
                    throw new RefundNotAllowed('nothing_owed', 'Nothing is owed on this order.', 409);
                }

                $number = (int) $attempts->max('attempt_no') + 1;
                // bf094ee's key format: the first refund of an order has no suffix, each retry adds one.
                $key = "refund-order-{$locked->id}".($number > 1 ? '-'.($number - 1) : '');

                $attempt = RefundAttempt::create([
                    'order_id' => $locked->id,
                    'attempt_no' => $number,
                    'trigger' => $trigger,
                    'channel' => RefundAttempt::CHANNEL_STRIPE,
                    'amount_cents' => $owed,
                    'currency' => (string) config('services.stripe.currency', 'usd'),
                    'status' => RefundAttempt::REQUESTED,
                    'stripe_idempotency_key' => $key,
                    'created_by' => $actor?->id,
                ]);
                $this->event($attempt, 'requested', $this->actor($actor, $trigger), array_filter([
                    'trigger' => $trigger,
                    'amount_cents' => $owed,
                    'reason' => $reason,
                ], fn ($v) => $v !== null));

                $this->syncOrder($locked);

                return $attempt;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new RefundInProgress;
            }

            throw $e;
        }
    }

    /**
     * Record a failed call to Stripe, then throw. A refusal that cannot be
     * retried closes the attempt; a timeout or Stripe outage leaves it open so
     * the queue retries it with the same key.
     *
     * @return never
     */
    private function handleStripeError(Order $order, RefundAttempt $attempt, Throwable $e): void
    {
        $code = $e instanceof ApiErrorException ? $e->getStripeCode() : null;
        $message = $this->cleanMessage($e->getMessage());
        $advice = RefundAdvice::forFailure($code, $message, 1);
        $permanent = in_array($advice['next_action'], [RefundAdvice::REFUND_MANUALLY, RefundAdvice::INVESTIGATE, RefundAdvice::WAIT_FOR_DISPUTE], true);

        DB::transaction(function () use ($order, $attempt, $code, $message, $advice, $permanent): void {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fresh = RefundAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            $fresh->forceFill([
                'status' => $permanent ? RefundAttempt::FAILED : $fresh->status,
                'failure_code' => $code,
                'failure_message' => $message,
                'retryable' => $advice['retryable'] && ! $permanent,
                'next_action' => $permanent ? $advice['next_action'] : RefundAdvice::AUTO_RETRY,
                'next_retry_at' => $permanent ? null : $this->nextRetryAt($fresh),
            ])->save();

            $this->event($fresh, 'stripe_error', 'stripe', ['code' => $code, 'message' => $message]);
            $this->event($fresh, $permanent ? 'failed' : 'retry_scheduled', 'system');
            $this->syncOrder($locked);
        });

        if ($permanent) {
            $this->alertOwner($order, $attempt->fresh(), $advice['next_action']);

            throw new RefundRefused($code, $message, $advice['next_action']);
        }

        throw $e;
    }

    /**
     * The queue job gave up, or an admin's own request failed: leave the
     * attempt open for a manual retry with the same key and say so.
     */
    public function markRetryable(Order $order, Throwable $e): void
    {
        $message = $this->cleanMessage($e->getMessage());
        $code = $e instanceof ApiErrorException ? $e->getStripeCode() : null;

        DB::transaction(function () use ($order, $message, $code): void {
            $attempt = RefundAttempt::query()->where('order_id', $order->id)
                ->where('status', RefundAttempt::REQUESTED)->lockForUpdate()->first();

            if (! $attempt) {
                $this->orderEvent($order->id, 'stripe_error', 'system', ['code' => $code, 'message' => $message]);

                return;
            }

            $attempt->forceFill([
                'failure_code' => $code,
                'failure_message' => $message,
                'retryable' => true,
                'next_action' => RefundAdvice::RETRY,
                'next_retry_at' => null,
            ])->save();
            $this->event($attempt, 'admin_alerted', 'system', ['message' => $message]);
        });
    }

    private function nextRetryAt(RefundAttempt $attempt): ?Carbon
    {
        $errors = RefundEvent::query()->where('attempt_id', $attempt->id)->where('event', 'stripe_error')->count();
        $backoff = SettleCancelledOrderPayment::BACKOFF;

        return isset($backoff[$errors]) ? now()->addSeconds($backoff[$errors]) : null;
    }

    // ── Webhooks, reconciliation ─────────────────────────────────────────────

    /**
     * Apply what Stripe says about one refund (a webhook, or the nightly
     * check). The refund is matched to our attempt by its id, then to the open
     * attempt that has no id yet; a refund the shop did not start becomes a
     * reconciliation entry. Returns false when the event was seen before.
     */
    public function recordStripeRefund(Order $order, object $stripeRefund, ?string $stripeEventId, string $actor = 'stripe'): bool
    {
        $alert = null;

        $recorded = (bool) DB::transaction(function () use ($order, $stripeRefund, $stripeEventId, $actor, &$alert): bool {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($stripeEventId && RefundEvent::query()->where('stripe_event_id', $stripeEventId)->exists()) {
                return false;
            }

            $refundId = $stripeRefund->id ?? null;
            $amount = isset($stripeRefund->amount) ? (int) $stripeRefund->amount : null;
            $attempts = RefundAttempt::query()->where('order_id', $locked->id)->lockForUpdate()->get();

            $attempt = $refundId ? $attempts->firstWhere('stripe_refund_id', $refundId) : null;
            $attempt ??= $attempts
                ->filter(fn (RefundAttempt $a) => ! $a->isManual() && $a->stripe_refund_id === null
                    && in_array($a->status, [RefundAttempt::REQUESTED, RefundAttempt::PENDING, RefundAttempt::SUCCEEDED], true)
                    && ($amount === null || $a->amount_cents === $amount))
                ->sortByDesc('attempt_no')->first();

            if (! $attempt) {
                $attempt = $this->createReconciliationAttempt(
                    $locked, $attempts, $amount ?? $locked->totalCents(), $stripeRefund,
                    null, $stripeEventId, $actor,
                );
                if (! $attempt) {
                    $this->orderEvent($locked->id, 'stripe_error', $actor, [
                        'message' => 'A refund appeared in Stripe while another refund was in progress.',
                        'refund_id' => $refundId,
                    ], $stripeEventId);

                    return true;
                }

                $this->syncOrder($locked);
                $alert = $attempt->status === RefundAttempt::FAILED ? $attempt->id : null;

                return true;
            }

            $failedNow = $this->applyStripeStatusInTransaction(
                $locked, $attempt->id, (string) ($stripeRefund->status ?? ''), $refundId,
                $stripeRefund->failure_reason ?? null, $actor, $stripeEventId,
            );
            $alert = $failedNow !== null ? $attempt->id : null;

            return true;
        });

        if ($alert !== null) {
            $attempt = RefundAttempt::query()->find($alert);
            $this->alertOwner($order, $attempt, (string) $attempt?->next_action, $stripeRefund->id ?? null);
        }

        return $recorded;
    }

    /**
     * The nightly check (R8) for one Stripe refund. Returns 'known' when the
     * record already agrees, 'updated' when a webhook was missed, 'added' when
     * the refund was made outside the shop. Writes nothing when nothing changed.
     */
    public function reconcileStripeRefund(Order $order, object $stripeRefund): string
    {
        $known = RefundAttempt::query()->where('stripe_refund_id', $stripeRefund->id ?? '')->first();
        $new = match ((string) ($stripeRefund->status ?? '')) {
            'succeeded' => RefundAttempt::SUCCEEDED,
            'pending', 'requires_action' => RefundAttempt::PENDING,
            'failed', 'canceled' => RefundAttempt::FAILED,
            default => null,
        };

        if ($known && ($new === null || $new === $known->status || ! $known->canMoveTo($new))) {
            return 'known';
        }

        $before = RefundAttempt::query()->where('order_id', $order->id)->count();
        $this->recordStripeRefund($order, $stripeRefund, null, 'system');

        return RefundAttempt::query()->where('order_id', $order->id)->count() > $before ? 'added' : 'updated';
    }

    /**
     * A refund still pending at Stripe after 5 business days needs a look in
     * Stripe. Alerts once per attempt; returns how many were flagged.
     */
    public function alertStalePending(): int
    {
        $limit = now()->subWeekdays(5);
        $flagged = 0;

        RefundAttempt::query()->where('status', RefundAttempt::PENDING)->where('created_at', '<=', $limit)
            ->with('order')->each(function (RefundAttempt $attempt) use (&$flagged): void {
                $alreadyAlerted = RefundEvent::query()->where('attempt_id', $attempt->id)
                    ->where('event', 'admin_alerted')->where('details->reason', 'pending_too_long')->exists();

                if ($alreadyAlerted || ! $attempt->order) {
                    return;
                }

                $this->event($attempt, 'admin_alerted', 'system', ['reason' => 'pending_too_long']);
                SendAdminAlert::dispatch(
                    "Refund {$attempt->stripe_refund_id} for order {$attempt->order->order_number} has been pending at Stripe for more than 5 business days. "
                    .'Check it in the Stripe Dashboard; do not refund the customer again until it is resolved.',
                    email: true,
                );
                $flagged++;
            });

        return $flagged;
    }

    /**
     * charge.refunded: when Stripe says more was refunded than the attempts
     * account for, someone refunded in the Stripe Dashboard. Add the
     * difference as a reconciliation entry (R8).
     */
    public function recordChargeRefunded(Order $order, int $amountRefundedCents, ?string $stripeEventId): bool
    {
        return (bool) DB::transaction(function () use ($order, $amountRefundedCents, $stripeEventId): bool {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($stripeEventId && RefundEvent::query()->where('stripe_event_id', $stripeEventId)->exists()) {
                return false;
            }

            $attempts = RefundAttempt::query()->where('order_id', $locked->id)->lockForUpdate()->get();
            $inStripe = (int) $attempts
                ->filter(fn (RefundAttempt $a) => ! $a->isManual())
                ->whereIn('status', [RefundAttempt::REQUESTED, RefundAttempt::PENDING, RefundAttempt::SUCCEEDED])
                ->sum('amount_cents');

            $difference = $amountRefundedCents - $inStripe;
            if ($difference <= 0) {
                return true;
            }

            $attempt = $this->createReconciliationAttempt($locked, $attempts, $difference, null, RefundAttempt::SUCCEEDED, $stripeEventId, 'stripe');
            if ($attempt) {
                $this->syncOrder($locked);
            }

            return true;
        });
    }

    private function createReconciliationAttempt(Order $order, Collection $attempts, int $amountCents, ?object $stripeRefund, ?string $status, ?string $stripeEventId, string $actor): ?RefundAttempt
    {
        $stripeStatus = (string) ($stripeRefund->status ?? '');
        $status ??= match ($stripeStatus) {
            'succeeded' => RefundAttempt::SUCCEEDED,
            'failed', 'canceled' => RefundAttempt::FAILED,
            default => RefundAttempt::PENDING,
        };

        $failure = [];
        if ($status === RefundAttempt::FAILED) {
            $code = ($stripeRefund->failure_reason ?? null) ?: ($stripeStatus === 'canceled' ? 'merchant_request' : null);
            $message = 'Stripe could not complete the refund'.($code ? ': '.str_replace('_', ' ', $code) : '.');
            $advice = RefundAdvice::forFailure($code, $message, 1);
            $failure = [
                'failure_code' => $code,
                'failure_message' => $message,
                'retryable' => $advice['retryable'],
                'next_action' => $advice['next_action'],
            ];
        }

        try {
            $attempt = RefundAttempt::create([
                'order_id' => $order->id,
                'attempt_no' => (int) $attempts->max('attempt_no') + 1,
                'trigger' => 'reconciliation',
                'channel' => RefundAttempt::CHANNEL_RECONCILIATION,
                'amount_cents' => $amountCents,
                'currency' => strtolower((string) ($stripeRefund->currency ?? config('services.stripe.currency', 'usd'))),
                'status' => $status,
                'stripe_refund_id' => $stripeRefund->id ?? null,
                'note' => 'Refund found in Stripe that the shop did not start.',
            ] + $failure);
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                return null;
            }

            throw $e;
        }

        $this->event($attempt, 'reconciled', $actor, array_filter([
            'refund_id' => $stripeRefund->id ?? null,
            'stripe_status' => $stripeStatus ?: null,
            'amount_cents' => $amountCents,
        ]), $stripeEventId);

        return $attempt;
    }

    /**
     * Move an attempt to what Stripe reports and alert the owner when a
     * refund newly fails (used for the call to Stripe itself).
     */
    private function applyStripeStatus(Order $order, int $attemptId, string $stripeStatus, ?string $refundId, ?string $failureReason, string $actor, ?string $stripeEventId): void
    {
        $alert = DB::transaction(function () use ($order, $attemptId, $stripeStatus, $refundId, $failureReason, $actor, $stripeEventId): ?string {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            return $this->applyStripeStatusInTransaction($locked, $attemptId, $stripeStatus, $refundId, $failureReason, $actor, $stripeEventId);
        });

        if ($alert !== null) {
            $this->alertOwner($order, RefundAttempt::query()->find($attemptId), $alert, $refundId);
        }
    }

    /**
     * Only forward (R6): a late "pending" cannot undo "succeeded", while a
     * bank can still fail a refund that already succeeded. Recalculates the
     * order. Returns the next_action to alert the owner about when the
     * refund newly failed, otherwise null. Needs the order lock.
     */
    private function applyStripeStatusInTransaction(Order $locked, int $attemptId, string $stripeStatus, ?string $refundId, ?string $failureReason, string $actor, ?string $stripeEventId): ?string
    {
        $newStatus = match ($stripeStatus) {
            'succeeded' => RefundAttempt::SUCCEEDED,
            'pending', 'requires_action' => RefundAttempt::PENDING,
            'failed', 'canceled' => RefundAttempt::FAILED,
            default => null,
        };

        $attempt = RefundAttempt::query()->whereKey($attemptId)->lockForUpdate()->firstOrFail();

        if ($refundId && $attempt->stripe_refund_id === null) {
            $attempt->stripe_refund_id = $refundId;
        }

        $details = array_filter(['refund_id' => $refundId, 'stripe_status' => $stripeStatus, 'failure_reason' => $failureReason]);

        if ($newStatus === null || ($newStatus !== $attempt->status && ! $attempt->canMoveTo($newStatus))) {
            $attempt->save();
            $this->event($attempt, 'ignored', $actor, $details + ['reason' => 'older than the current status'], $stripeEventId);

            return null;
        }

        if ($newStatus === $attempt->status) {
            $attempt->save();
            $this->event($attempt, $newStatus, $actor, $details + ['repeated' => true], $stripeEventId);

            return null;
        }

        $attempt->status = $newStatus;
        $alert = null;

        if ($newStatus === RefundAttempt::FAILED) {
            $code = $failureReason ?? ($stripeStatus === 'canceled' ? 'merchant_request' : null);
            $failedBefore = RefundAttempt::query()->where('order_id', $locked->id)->where('status', RefundAttempt::FAILED)->count();
            $message = 'Stripe could not complete the refund'.($code ? ': '.str_replace('_', ' ', $code) : '.');
            $advice = RefundAdvice::forFailure($code, $message, $failedBefore + 1);

            $attempt->forceFill([
                'failure_code' => $code,
                'failure_message' => $message,
                'retryable' => $advice['retryable'],
                'next_action' => $advice['next_action'],
                'next_retry_at' => null,
            ]);
            $alert = $advice['next_action'];
        } else {
            $attempt->forceFill([
                'failure_code' => null,
                'failure_message' => null,
                'retryable' => false,
                'next_action' => $newStatus === RefundAttempt::PENDING ? RefundAdvice::WAIT : RefundAdvice::NONE,
                'next_retry_at' => null,
            ]);
        }

        $attempt->save();
        $this->event($attempt, $newStatus, $actor, $details, $stripeEventId);
        $this->syncOrder($locked);

        if ($alert !== null) {
            Log::critical('Stripe refund failed.', [
                'order_id' => $locked->id,
                'refund_id' => $refundId,
                'failure_reason' => $failureReason,
            ]);
        }

        return $alert;
    }

    // ── Manual refunds (R4, R5) ──────────────────────────────────────────────

    /**
     * Record money the owner already sent by Zelle or PayPal. This moves no
     * money; it makes the shop's books match what happened.
     *
     * @throws RefundNotAllowed
     */
    public function recordManual(
        Order $order,
        User $actor,
        int $amountCents,
        string $method,
        string $reference,
        Carbon $sentAt,
        ?string $note = null,
        ?string $overrideReason = null,
        bool $fromLog = false,
        ?int $logLine = null,
    ): RefundAttempt {
        $this->quiet = $fromLog;

        try {
            $attempt = DB::transaction(function () use ($order, $actor, $amountCents, $method, $reference, $sentAt, $note, $overrideReason, $fromLog, $logLine): RefundAttempt {
                $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                $attempts = RefundAttempt::query()->where('order_id', $locked->id)->lockForUpdate()->get();

                if (! in_array($locked->payment_status, ['paid', 'refunded'], true)) {
                    throw new RefundNotAllowed('nothing_owed', 'No payment was taken for this order, so there is nothing to refund.', 409);
                }

                if ($attempts->contains(fn (RefundAttempt $a) => $a->status === RefundAttempt::PENDING)) {
                    throw new RefundInProgress;
                }

                if ($this->owedFrom($locked, $attempts->reject(fn (RefundAttempt $a) => $a->status === RefundAttempt::REQUESTED)) <= 0) {
                    throw new RefundNotAllowed('nothing_owed', 'Nothing is owed on this order.', 409);
                }

                // R4: the card route has to be finished. Anything else needs a written reason.
                $blocker = $this->manualBlocker($locked, $attempts);
                if ($blocker !== null && trim((string) $overrideReason) === '') {
                    throw new RefundNotAllowed($blocker, match ($blocker) {
                        'refund_blocked' => 'This refund is waiting for Stripe or a check; give a reason to record a manual refund anyway.',
                        default => 'The refund can still be retried through Stripe; give a reason to record a manual refund anyway.',
                    }, 409, ['next_action' => $this->adviceFor($locked, $attempts)['next_action']]);
                }

                $abandoned = null;
                $requested = $attempts->first(fn (RefundAttempt $a) => $a->status === RefundAttempt::REQUESTED);
                if ($requested) {
                    $abandoned = $requested->id;
                    $requested->forceFill([
                        'status' => RefundAttempt::FAILED,
                        'retryable' => false,
                        'next_action' => RefundAdvice::REFUND_MANUALLY,
                        'failure_message' => $requested->failure_message ?: 'Abandoned: the refund was sent manually.',
                    ])->save();
                    $this->event($requested, 'failed', $this->actor($actor), ['reason' => 'abandoned for a manual refund']);
                    $attempts = RefundAttempt::query()->where('order_id', $locked->id)->get();
                }

                $owed = $this->owedFrom($locked, $attempts);
                if ($owed <= 0) {
                    throw new RefundNotAllowed('nothing_owed', 'Nothing is owed on this order.', 409);
                }
                if ($amountCents > $owed) {
                    throw new RefundNotAllowed('amount_exceeds_owed', 'The amount exceeds what is still owed.', 422, [
                        'amount' => ['The amount exceeds what is still owed ($'.number_format($owed / 100, 2, '.', '').').'],
                    ]);
                }

                $attempt = RefundAttempt::create([
                    'order_id' => $locked->id,
                    'attempt_no' => (int) $attempts->max('attempt_no') + 1,
                    'trigger' => 'manual',
                    'channel' => RefundAttempt::CHANNEL_MANUAL,
                    'amount_cents' => $amountCents,
                    'currency' => (string) config('services.stripe.currency', 'usd'),
                    'status' => RefundAttempt::RECORDED,
                    'method' => $method,
                    'external_reference' => $reference,
                    'sent_at' => $sentAt,
                    'created_by' => $actor->id,
                    'note' => $note,
                ]);
                $this->event($attempt, $fromLog ? 'imported_from_log' : 'manual_recorded', $this->actor($actor), array_filter([
                    'amount_cents' => $amountCents,
                    'method' => $method,
                    'reference' => $reference,
                    'sent_at' => $sentAt->toIso8601String(),
                    'override_reason' => $overrideReason,
                    'abandoned_attempt_id' => $abandoned,
                    'log_line' => $logLine,
                ], fn ($v) => $v !== null));

                $this->syncOrder($locked);

                return $attempt;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new RefundInProgress;
            }

            throw $e;
        } finally {
            $this->quiet = false;
        }

        return $attempt;
    }

    /**
     * Correct a wrong manual entry. The entry stays in the history, crossed
     * out, and the amount owed goes back up.
     *
     * @throws RefundNotAllowed
     */
    public function voidManual(Order $order, RefundAttempt $attempt, User $actor, string $reason): RefundAttempt
    {
        return DB::transaction(function () use ($order, $attempt, $actor, $reason): RefundAttempt {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $fresh = RefundAttempt::query()->whereKey($attempt->id)->where('order_id', $locked->id)->lockForUpdate()->firstOrFail();

            if (! $fresh->isManual() || $fresh->status !== RefundAttempt::RECORDED) {
                throw new RefundNotAllowed('not_voidable', 'Only a recorded manual refund can be voided.', 409);
            }

            $fresh->forceFill(['status' => RefundAttempt::VOIDED])->save();
            $this->event($fresh, 'voided', $this->actor($actor), ['reason' => $reason]);
            $this->syncOrder($locked);

            return $fresh;
        });
    }

    /**
     * @param  Collection<int, RefundAttempt>  $attempts
     * @return string|null why a manual refund needs a written reason, null when the card route is finished
     */
    private function manualBlocker(Order $order, Collection $attempts): ?string
    {
        $advice = $this->adviceFor($order, $attempts);

        if ($order->refund_status === 'failed' && $advice['next_action'] === RefundAdvice::REFUND_MANUALLY
            && ! $attempts->contains(fn (RefundAttempt $a) => $a->status === RefundAttempt::REQUESTED)) {
            return null;
        }

        return in_array($advice['next_action'], [RefundAdvice::WAIT_FOR_DISPUTE, RefundAdvice::INVESTIGATE], true)
            ? 'refund_blocked'
            : 'retry_available';
    }

    /**
     * @param  Collection<int, RefundAttempt>  $attempts
     * @return array{retryable: bool, next_action: string, requires_reason: bool, next_retry_at?: ?CarbonInterface}
     */
    public function adviceFor(Order $order, Collection $attempts): array
    {
        $open = $attempts->first(fn (RefundAttempt $a) => $a->status === RefundAttempt::REQUESTED);

        if ($open && $order->refund_status === 'pending' && $open->next_action === RefundAdvice::AUTO_RETRY) {
            return ['retryable' => false, 'next_action' => RefundAdvice::AUTO_RETRY, 'requires_reason' => false, 'next_retry_at' => $open->next_retry_at];
        }

        return RefundAdvice::forRefundState(
            (string) $order->refund_status,
            $order->refund_failure_code,
            $order->refund_failure_message,
            (int) $order->refund_failures,
        );
    }

    // ── The order is calculated from its attempts (R7) ───────────────────────

    /**
     * Recalculate the order's money state from its attempts. Call inside the
     * transaction that holds the order lock, with the locked order.
     */
    public function syncOrder(Order $order): void
    {
        $attempts = RefundAttempt::query()->where('order_id', $order->id)->orderBy('attempt_no')->get();
        if ($attempts->isEmpty()) {
            return;
        }

        $total = $order->totalCents();
        $live = $attempts->where('status', '!=', RefundAttempt::VOIDED)->values();
        $done = $live->whereIn('status', RefundAttempt::DONE);
        $doneCents = (int) $done->sum('amount_cents');
        $open = $live->first(fn (RefundAttempt $a) => $a->isOpen());
        $last = $live->last();
        $wasRefunded = $order->payment_status === 'refunded';

        $channels = $done->map(fn (RefundAttempt $a) => $a->isManual() ? 'manual' : 'stripe')->unique();
        $attributes = [
            'refunded_amount' => $doneCents / 100,
            'refunded_via' => $channels->count() > 1 ? 'mixed' : $channels->first(),
            'refunded_method' => $done->filter(fn (RefundAttempt $a) => $a->isManual())->last()?->method,
            'refund_failures' => $attempts->where('status', RefundAttempt::FAILED)->reject->isManual()->count(),
        ];
        $clearFailure = ['refund_failure_code' => null, 'refund_failure_message' => null, 'refund_failed_at' => null];
        $paymentStatus = 'completed';

        if ($total > 0 && $doneCents >= $total) {
            $attributes += $clearFailure + [
                'refund_status' => 'succeeded',
                'payment_status' => 'refunded',
                'refunded_at' => $order->refunded_at ?? now(),
            ];
            if (! in_array($order->status, ['cancelled', 'refunded'], true)) {
                $attributes['status'] = 'refunded';
            }
            $paymentStatus = 'refunded';
        } else {
            // The money has not (fully) left: a refund that read "refunded" too early goes back to "paid" (B2).
            if ($wasRefunded) {
                $attributes['payment_status'] = 'paid';
            }
            $attributes['refunded_at'] = null;
            if ($order->status === 'refunded') {
                $attributes['status'] = $order->shipped_at ? 'delivered' : 'processing';
            }
            if ($doneCents > 0) {
                $paymentStatus = 'partially_refunded';
            }

            $source = $open ?? ($last?->status === RefundAttempt::FAILED ? $last : null);

            if ($open) {
                $attributes += ['refund_status' => 'pending'] + $this->failureColumns($open);
            } elseif ($last === null) {
                $attributes += ['refund_status' => 'failed'] + $clearFailure;
                $attributes['refund_failure_message'] = 'The recorded manual refund was voided.';
                $attributes['refund_failed_at'] = now();
            } elseif ($source) {
                $attributes += ['refund_status' => 'failed'] + $this->failureColumns($source);
            }
        }

        $order->forceFill($attributes)->save();

        Payment::query()->where('order_id', $order->id)->update([
            'status' => $paymentStatus,
            'updated_at' => now(),
        ]);

        if ($paymentStatus === 'refunded' && ! $wasRefunded) {
            $this->emailCustomer($order);
        }
    }

    /** @return array<string, mixed> */
    private function failureColumns(RefundAttempt $attempt): array
    {
        return [
            'refund_failure_code' => $attempt->failure_code,
            'refund_failure_message' => $attempt->failure_message,
            'refund_failed_at' => $attempt->failure_message !== null || $attempt->failure_code !== null ? ($attempt->updated_at ?? now()) : null,
        ];
    }

    private function emailCustomer(Order $order): void
    {
        if ($this->quiet) {
            return;
        }

        $email = $order->user()->value('email');
        if (! $email || RefundEvent::query()->where('order_id', $order->id)->where('event', 'customer_emailed')->exists()) {
            return;
        }

        DB::afterCommit(function () use ($order, $email): void {
            try {
                Mail::to($email)->queue(new OrderRefundedMail($order->fresh()));
            } catch (Throwable $e) {
                Log::error('Refund email could not be queued.', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            }
        });

        $this->orderEvent($order->id, 'customer_emailed', 'system', ['to' => $email]);
    }

    // ── Presentation (admin API) ─────────────────────────────────────────────

    /**
     * The refund block of the admin order: what is owed, what to do next, and
     * the history. Needs the attempts (and, for the history, their events).
     *
     * @param  Collection<int, RefundAttempt>  $attempts
     * @return array<string, mixed>
     */
    public function present(Order $order, Collection $attempts, bool $withEvents): array
    {
        $advice = $this->adviceFor($order, $attempts);
        $doneCents = (int) $attempts->whereIn('status', RefundAttempt::DONE)->sum('amount_cents');

        return [
            'retryable' => $advice['retryable'],
            'next_action' => $advice['next_action'],
            'requires_reason' => $advice['requires_reason'],
            'next_retry_at' => ($advice['next_retry_at'] ?? null)?->toIso8601String(),
            'refunded_via' => $order->refunded_via,
            'amount_paid' => $this->money($order->totalCents()),
            'amount_refunded' => $this->money($doneCents),
            'amount_owed' => $this->money($this->owedFrom($order, $attempts)),
            'attempts' => $attempts->map(fn (RefundAttempt $a) => $this->presentAttempt($a, $withEvents))->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentAttempt(RefundAttempt $a, bool $withEvents = false): array
    {
        $payload = [
            'id' => $a->id,
            'attempt_no' => $a->attempt_no,
            'trigger' => $a->trigger,
            'channel' => $a->channel,
            'amount' => $this->money($a->amount_cents),
            'currency' => $a->currency,
            'status' => $a->status,
            'stripe_refund_id' => $a->stripe_refund_id,
            'failure_code' => $a->failure_code,
            'method' => $a->method,
            'external_reference' => $a->external_reference,
            'sent_at' => $a->sent_at?->toIso8601String(),
            'note' => $a->note,
            'created_by' => $a->relationLoaded('creator') && $a->creator ? ['id' => $a->creator->id, 'name' => $a->creator->name] : ($a->created_by ? ['id' => $a->created_by] : null),
            'created_at' => $a->created_at->toIso8601String(),
        ];

        if ($withEvents && $a->relationLoaded('events')) {
            $payload['events'] = $a->events->map(fn (RefundEvent $e) => [
                'event' => $e->event,
                'actor' => $e->actor,
                'details' => $e->details,
                'at' => $e->created_at->toIso8601String(),
            ])->values()->all();
        }

        return $payload;
    }

    private function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $details */
    private function event(RefundAttempt $attempt, string $event, string $actor = 'system', array $details = [], ?string $stripeEventId = null): RefundEvent
    {
        return $this->orderEvent($attempt->order_id, $event, $actor, $details, $stripeEventId, $attempt->id);
    }

    /** @param  array<string, mixed>  $details */
    private function orderEvent(int $orderId, string $event, string $actor = 'system', array $details = [], ?string $stripeEventId = null, ?int $attemptId = null): RefundEvent
    {
        return RefundEvent::create([
            'order_id' => $orderId,
            'attempt_id' => $attemptId,
            'event' => $event,
            'actor' => $actor,
            'stripe_event_id' => $stripeEventId,
            'details' => $details ?: null,
        ]);
    }

    /** `customer:{id}` for the customer's own cancel, `admin:{id}` for staff, `system` for automatic steps. */
    private function actor(?User $user, string $trigger = ''): string
    {
        if (! $user) {
            return 'system';
        }

        return ($trigger === 'customer_cancel' ? 'customer:' : 'admin:').$user->id;
    }

    private function alertOwner(Order $order, ?RefundAttempt $attempt, string $nextAction, ?string $refundId = null): void
    {
        $code = $attempt?->failure_code;
        SendAdminAlert::dispatch(
            'URGENT: Stripe refund'.($refundId ? " {$refundId}" : '')." failed for order {$order->order_number}"
            .($code ? " ({$code})" : '').'. '.RefundAdvice::ownerInstruction($nextAction),
            email: true,
        )->onQueue('notifications');

        if ($attempt) {
            $this->event($attempt, 'admin_alerted', 'system', ['next_action' => $nextAction]);
        }
    }

    private function cleanMessage(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? '');

        return mb_substr($message !== '' ? $message : 'Payment provider error.', 0, 1000);
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        return in_array((string) $e->getCode(), ['23000', '23505'], true) && str_contains(strtolower($e->getMessage()), 'duplicate');
    }
}
