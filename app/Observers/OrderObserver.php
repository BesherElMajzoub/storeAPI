<?php

namespace App\Observers;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\ShippingRateQuote;
use App\Services\OrderInventoryService;
use Illuminate\Support\Facades\DB;

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
            $previousStatus = $order->getRawOriginal('status');

            // An order that never completed payment got no value from its
            // coupon or its held shipping quote, so cancelling it must give
            // both back. An order that WAS paid (payment_status === 'paid')
            // and is later cancelled/refunded already delivered that value —
            // whether to also release the coupon there is a refund-policy
            // call, not something to infer here (see D7-F1 NEEDS-DECISION).
            if ($order->status === 'cancelled' && $order->payment_status !== 'paid') {
                $this->releaseCouponAndQuote($order);
            }

            if ($order->shipped_at !== null || in_array($previousStatus, ['shipped', 'delivered'], true)) {
                return;
            }

            app(OrderInventoryService::class)->release($order);

            return;
        }

        if (in_array($order->status, $this->reservedStates, true) && ! $order->stock_reserved_at) {
            app(OrderInventoryService::class)->reserveExistingOrder($order);
        }
    }

    private function releaseCouponAndQuote(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $usage = CouponUsage::query()->where('order_id', $order->id)->lockForUpdate()->first();

            if ($usage) {
                $coupon = Coupon::query()->whereKey($usage->coupon_id)->lockForUpdate()->first();
                if ($coupon && $coupon->used_count > 0) {
                    $coupon->decrement('used_count');
                }
                $usage->delete();
            }

            ShippingRateQuote::query()
                ->where('order_id', $order->id)
                ->update(['consumed_at' => null, 'order_id' => null]);
        });
    }
}
