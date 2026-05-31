<?php

namespace App\Services;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\User;
use App\Exceptions\CouponValidationException;
use Illuminate\Support\Facades\DB;

class CouponService
{
    /**
     * Validate a coupon code.
     *
     * @throws CouponValidationException
     */
    public function validateCoupon(string $code, ?User $user, float $subtotal): Coupon
    {
        $query = Coupon::where('code', $code);
        
        // Dynamically lock for update if inside a database transaction
        if (DB::transactionLevel() > 0) {
            $query->lockForUpdate();
        }

        $coupon = $query->first();

        if (!$coupon) {
            throw new CouponValidationException('Invalid coupon code.');
        }

        if (!$coupon->is_active) {
            throw new CouponValidationException('Invalid coupon code.');
        }

        $now = now();
        if ($coupon->starts_at && $coupon->starts_at->isFuture()) {
            throw new CouponValidationException('Coupon is not active yet.');
        }

        if ($coupon->expires_at && $coupon->expires_at->isPast()) {
            throw new CouponValidationException('Coupon has expired.');
        }

        if ($coupon->minimum_order_amount !== null && $subtotal < (float) $coupon->minimum_order_amount) {
            throw new CouponValidationException('Minimum order amount not met.');
        }

        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            throw new CouponValidationException('Coupon usage limit reached.');
        }

        if ($coupon->usage_limit_per_user !== null) {
            if (!$user) {
                throw new CouponValidationException('Authentication required to use this coupon.');
            }

            $userUsageCount = CouponUsage::where('coupon_id', $coupon->id)
                ->where('user_id', $user->id)
                ->count();

            if ($userUsageCount >= $coupon->usage_limit_per_user) {
                throw new CouponValidationException('You have exceeded the usage limit for this coupon.');
            }
        }

        return $coupon;
    }

    /**
     * Calculate the discount amount for a coupon on a given subtotal.
     */
    public function calculateDiscount(Coupon $coupon, float $subtotal): float
    {
        $discount = 0.0;

        if ($coupon->type === 'percentage') {
            $discount = $subtotal * ((float) $coupon->value / 100.0);
            if ($coupon->maximum_discount_amount !== null && $discount > (float) $coupon->maximum_discount_amount) {
                $discount = (float) $coupon->maximum_discount_amount;
            }
        } else { // fixed
            $discount = (float) $coupon->value;
        }

        if ($discount > $subtotal) {
            $discount = $subtotal;
        }

        return round($discount, 2);
    }

    /**
     * Apply a coupon and its calculated discount to an order.
     */
    public function applyCouponToOrder(Coupon $coupon, Order $order, float $discount): void
    {
        $order->coupon_id = $coupon->id;
        $order->coupon_code = $coupon->code;
        $order->discount = $discount;
        
        $subtotal = (float) $order->subtotal;
        $tax = (float) $order->tax;
        $shipping = (float) $order->shipping_cost;
        
        $order->total = max(0.0, $subtotal + $tax + $shipping - $discount);
    }
}
