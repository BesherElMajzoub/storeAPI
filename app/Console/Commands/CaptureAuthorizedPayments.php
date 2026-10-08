<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Services\OrderPaymentService;
use Illuminate\Console\Command;

class CaptureAuthorizedPayments extends Command
{
    protected $signature = 'orders:capture-authorized-payments';

    protected $description = 'Capture held card payments once the customer cancellation window has closed.';

    public function handle(OrderPaymentService $payments): int
    {
        Order::query()->where('status', 'processing')->where('payment_status', 'authorized')
            ->where('authorized_at', '<=', now()->subMinutes(Order::directCancelWindowMinutes()))
            // While a cancellation request awaits the admin, keep the hold so
            // accepting it releases the card instead of paying for a refund.
            // Card holds expire after ~7 days, so capture anyway after 5.
            ->where(fn ($query) => $query
                ->whereNotIn('id', OrderCancellationRequest::query()->select('order_id')->where('status', 'pending'))
                ->orWhere('authorized_at', '<=', now()->subDays(5)))
            ->orderBy('id')->chunkById(100, function ($orders) use ($payments): void {
                foreach ($orders as $order) {
                    try {
                        $payments->capture($order);
                    } catch (\Throwable $e) {
                        $payments->recordCaptureError($order, $e);
                    }
                }
            });

        return self::SUCCESS;
    }
}
