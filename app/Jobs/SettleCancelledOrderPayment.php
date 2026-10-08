<?php

namespace App\Jobs;

use App\Models\Order;
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

    public $backoff = [60, 300, 900, 1800, 3600];

    public function __construct(public int $orderId) {}

    public function handle(OrderPaymentService $payments): void
    {
        $order = Order::withTrashed()->find($this->orderId);

        if ($order) {
            $payments->settleCancellation($order);
        }
    }

    public function failed(Throwable $e): void
    {
        $order = Order::withTrashed()->find($this->orderId);

        if (! $order || $order->refund_status !== 'pending') {
            return;
        }

        $order->update(['refund_status' => 'failed']);

        Log::critical('Returning money for a cancelled order failed after all retries.', [
            'order_id' => $order->id,
            'error' => $e->getMessage(),
        ]);
        SendAdminAlert::dispatch("URGENT: could not return payment for cancelled order {$order->order_number}. Retry it from the admin panel.");
    }
}
