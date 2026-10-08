<?php

namespace App\Observers;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\ShippingRateQuote;
use App\Services\OrderInventoryService;
use App\Services\OrderPaymentService;
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

            // A cancelled order never shipped (shipped orders cannot be
            // cancelled), so the customer got nothing for the coupon or the
            // held shipping quote: give both back, paid or not. A refund
            // after delivery (status 'refunded') keeps the coupon used, so
            // returns cannot be used to recycle one-time coupons (D7-F1).
            if ($order->status === 'cancelled') {
                $this->releaseCouponAndQuote($order);
            }

            // Every cancellation path (customer, request approval, admin,
            // bulk) lands here, so this is where held or captured money is
            // queued to go back to the customer.
            if ($order->status === 'cancelled'
                && in_array($order->payment_status, ['authorized', 'paid'], true)
                && $order->stripe_payment_intent_id
                && $order->refund_status === 'none') {
                app(OrderPaymentService::class)->queueSettlement($order);
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
