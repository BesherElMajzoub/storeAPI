<?php

namespace App\Console\Commands;

use App\Jobs\SendAdminAlert;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AlertStaleAuthorizations extends Command
{
    protected $signature = 'orders:alert-stale-authorizations';

    protected $description = 'Alert the admin about card holds that are still not captured long after checkout.';

    public function handle(): int
    {
        $hours = max(1, (int) config('orders.stale_authorization_alert_hours', 6));

        $orders = Order::query()
            ->where('payment_status', 'authorized')
            ->where('authorized_at', '<=', now()->subHours($hours))
            ->orderBy('id')
            ->get(['id', 'order_number', 'authorized_at']);

        foreach ($orders as $order) {
            // One alert per order per day, however often the check runs.
            if (! Cache::add("orders:stale-authorization-alert:{$order->id}", true, now()->addDay())) {
                continue;
            }

            SendAdminAlert::dispatch(
                "Order {$order->order_number} has held the customer's card for more than {$hours} hours without capture "
                .'(authorized '.$order->authorized_at->toIso8601String().'). Check the scheduler and queue worker, '
                .'and decide any pending cancellation request.',
                email: true,
            );
        }

        $this->info("Stale authorizations alerted: {$orders->count()} checked.");

        return self::SUCCESS;
    }
}
