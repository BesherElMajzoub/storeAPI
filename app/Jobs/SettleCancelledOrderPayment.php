<?php

namespace App\Jobs;

use App\Exceptions\RefundNotAllowed;
use App\Exceptions\RefundRefused;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderPaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Releases the card hold (or refunds the captured charge) of a cancelled
 * order. Runs after the cancellation commits, so a Stripe outage never undoes
 * the cancellation; it retries with backoff and alerts admins if it gives up.
 */
class SettleCancelledOrderPayment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $tries = 6;

    /** Seconds before each retry; RefundService shows the next one as next_retry_at. */
    public const BACKOFF = [60, 300, 900, 1800, 3600];

    public $backoff = self::BACKOFF;

    public function __construct(
        public int $orderId,
        public string $trigger = 'system',
        public ?int $actorId = null,
    ) {}

    public function handle(OrderPaymentService $payments): void
    {
        $order = Order::withTrashed()->find($this->orderId);

        if (! $order) {
            return;
        }

        try {
            $payments->settleCancellation($order, $this->trigger, $this->actorId ? User::find($this->actorId) : null);
        } catch (RefundRefused|RefundNotAllowed $e) {
            // Retrying cannot change the answer; the refund record and the owner alert already say what to do.
            $this->fail($e);
        }
    }

    public function failed(Throwable $e): void
    {
        $order = Order::withTrashed()->find($this->orderId);

        if (! $order || $order->refund_status !== 'pending' || $e instanceof RefundRefused || $e instanceof RefundNotAllowed) {
            return;
        }

        app(OrderPaymentService::class)->recordRefundFailure($order, $e);

        Log::critical('Returning money for a cancelled order failed after all retries.', [
            'order_id' => $order->id,
            'error' => $e->getMessage(),
        ]);
        SendAdminAlert::dispatch("URGENT: could not return payment for cancelled order {$order->order_number}. Retry it from the admin panel.", email: true);
    }
}
