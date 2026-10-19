<?php

namespace App\Console\Commands;

use App\Jobs\SendAdminAlert;
use App\Models\Order;
use App\Services\RefundService;
use App\Services\StripeCheckoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rule R8: compares the refunds Stripe holds for the last days with the
 * refund record. A refund made directly in the Stripe Dashboard is added as a
 * reconciliation entry, a status the webhooks missed is caught up, and any
 * difference is logged and emailed to the owner.
 */
class ReconcileRefunds extends Command
{
    protected $signature = 'refunds:reconcile {--days=30 : How many days of Stripe refunds to compare}';

    protected $description = 'Compare Stripe refunds with the refund record and alert on differences.';

    public function handle(StripeCheckoutService $stripe, RefundService $refunds): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')))->timestamp;
        $counts = ['checked' => 0, 'known' => 0, 'updated' => 0, 'added' => 0, 'unmatched' => 0];
        $notes = [];

        foreach ($stripe->listRefundsSince($since) as $refund) {
            $counts['checked']++;

            $intentId = $refund->payment_intent ?? null;
            $order = $intentId ? Order::query()->where('stripe_payment_intent_id', $intentId)->first() : null;

            if (! $order) {
                // Refunds for payments this shop did not take (another store on the account, test data).
                $counts['unmatched']++;
                Log::info('Stripe refund does not belong to any order.', ['refund_id' => $refund->id ?? null]);

                continue;
            }

            $result = $refunds->reconcileStripeRefund($order, $refund);
            $counts[$result]++;

            if ($result === 'added') {
                $notes[] = "{$refund->id} for order {$order->order_number} was made outside the shop (Stripe Dashboard?) and has been added to the record.";
            } elseif ($result === 'updated') {
                $notes[] = "{$refund->id} for order {$order->order_number} had a different status in Stripe; the record was updated.";
            }
        }

        $stale = $refunds->alertStalePending();

        $this->info(sprintf(
            'Checked %d Stripe refunds: %d known, %d updated, %d added, %d not ours. %d pending refund(s) flagged.',
            $counts['checked'], $counts['known'], $counts['updated'], $counts['added'], $counts['unmatched'], $stale,
        ));

        if ($notes !== []) {
            Log::warning('Refund reconciliation found differences.', ['differences' => $notes]);
            SendAdminAlert::dispatch(
                "Refund check against Stripe found differences:\n- ".implode("\n- ", $notes),
                email: true,
            );
        }

        return self::SUCCESS;
    }
}
