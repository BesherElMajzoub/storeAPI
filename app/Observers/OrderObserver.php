<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\OrderInventoryService;

class OrderObserver
{
    private array $reservedStates = ['processing', 'shipped', 'delivered'];

    private array $releaseStates = ['cancelled', 'refunded'];

    public function created(Order $order): void
    {
        if (in_array($order->status, $this->reservedStates, true)) {
            app(OrderInventoryService::class)->reserveExistingOrder($order);
        }
    }

    public function updated(Order $order): void
    {
        if (! $order->wasChanged('status')) {
            return;
        }

        if (in_array($order->status, $this->releaseStates, true)) {
            app(OrderInventoryService::class)->release($order);

            return;
        }

        if (in_array($order->status, $this->reservedStates, true) && ! $order->stock_reserved_at) {
            app(OrderInventoryService::class)->reserveExistingOrder($order);
        }
    }
}
