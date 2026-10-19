<?php

namespace App\Support;

/**
 * Decides what an admin may do after a card refund failed. The failure is
 * either a Stripe API error code (the request was refused) or a refund
 * failure_reason (the bank refused it later); the result tells the admin
 * page which single action to offer, so Retry is only shown when it can work.
 *
 * Reasons Stripe is known to refuse a new refund for are not retryable: the
 * money is already back in the shop's Stripe balance and has to be returned
 * by Zelle or PayPal.
 */
final class RefundAdvice
{
    public const NONE = 'none';

    public const WAIT = 'wait';

    public const RETRY = 'retry';

    public const TOP_UP_THEN_RETRY = 'top_up_then_retry';

    public const WAIT_FOR_DISPUTE = 'wait_for_dispute';

    public const REFUND_MANUALLY = 'refund_manually';

    public const INVESTIGATE = 'investigate';

    /** The shop retries by itself (same attempt, same Stripe key) and nobody has to act yet. */
    public const AUTO_RETRY = 'auto_retry';

    /** Actions that need a person; the admin list filter and dashboard count use them. */
    public const NEEDS_ACTION = [self::RETRY, self::TOP_UP_THEN_RETRY, self::REFUND_MANUALLY, self::INVESTIGATE];

    /** Failure codes where the customer's bank refused the refund to the card. */
    public const CARD_ROUTE_CLOSED = ['expired_or_canceled_card', 'lost_or_stolen_card', 'declined'];

    /** Failure codes where a dispute decides where the money goes. */
    public const DISPUTE = ['charge_for_pending_refund_disputed', 'charge_disputed'];

    /** Failure codes that mean the shop's own data disagrees with Stripe. */
    public const DATA_ERRORS = ['charge_already_refunded', 'amount_too_large'];

    /**
     * @return array{retryable: bool, next_action: string, requires_reason: bool}
     */
    public static function forFailure(?string $code, ?string $message, int $failures = 1): array
    {
        $code = $code !== null && $code !== '' ? $code : null;

        // Stripe refuses a second refund of a charge whose earlier refund failed at the bank.
        if ($message !== null && stripos($message, 'previous attempt to refund') !== false) {
            return self::result(self::REFUND_MANUALLY);
        }

        if ($code !== null && in_array($code, self::CARD_ROUTE_CLOSED, true)) {
            return self::result(self::REFUND_MANUALLY);
        }

        if ($code !== null && in_array($code, self::DISPUTE, true)) {
            return self::result(self::WAIT_FOR_DISPUTE);
        }

        if ($code !== null && in_array($code, self::DATA_ERRORS, true)) {
            return self::result(self::INVESTIGATE);
        }

        if ($code === 'insufficient_funds') {
            return self::result(self::TOP_UP_THEN_RETRY, true);
        }

        if ($code === 'merchant_request') {
            return self::result(self::RETRY, true, true);
        }

        // `unknown`, a timeout, a Stripe outage or a code not listed here: one
        // more try is safe, then the money goes back by hand.
        return $failures >= 2
            ? self::result(self::REFUND_MANUALLY)
            : self::result(self::RETRY, true);
    }

    /**
     * @return array{retryable: bool, next_action: string, requires_reason: bool}
     */
    public static function forRefundState(string $refundStatus, ?string $code, ?string $message, int $failures = 1): array
    {
        return match ($refundStatus) {
            'pending' => self::result(self::WAIT),
            'failed' => self::forFailure($code, $message, $failures),
            default => self::result(self::NONE),
        };
    }

    /** What the owner has to do, in a sentence for the alert email. */
    public static function ownerInstruction(string $nextAction): string
    {
        return match ($nextAction) {
            self::REFUND_MANUALLY => "The customer's bank refused the refund to the card and the money is back in the Stripe balance: contact the customer within 2 business days and refund by Zelle or PayPal.",
            self::TOP_UP_THEN_RETRY => 'The Stripe balance was too low: add funds in Stripe, then retry the refund from the admin panel.',
            self::WAIT_FOR_DISPUTE => 'The customer opened a dispute: Stripe decides where the money goes, do not refund separately.',
            self::INVESTIGATE => 'The refund does not match Stripe: check the charge in Stripe before refunding anything.',
            default => 'You can retry the refund from the admin panel.',
        };
    }

    /** @return array{retryable: bool, next_action: string, requires_reason: bool} */
    private static function result(string $action, bool $retryable = false, bool $requiresReason = false): array
    {
        return ['retryable' => $retryable, 'next_action' => $action, 'requires_reason' => $requiresReason];
    }
}
