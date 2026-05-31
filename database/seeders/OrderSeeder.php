<?php

namespace Database\Seeders;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\OrderCancellationRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class OrderSeeder extends Seeder
{
    public function run(): void
    {
        // Disable order event dispatchers (observers) to prevent decrementing stock or sending real alerts
        Order::unsetEventDispatcher();

        $userWithOrders = User::where('email', 'user_with_orders@store.com')->first();
        $userCancelled = User::where('email', 'user_cancelled_orders@store.com')->first();
        $userMany = User::where('email', 'user_many_orders@store.com')->first();
        $customer = User::where('email', 'customer@store.com')->first();

        // 1) Order 1: Pending payment for user_with_orders
        if ($userWithOrders) {
            $abaya = Product::where('slug', 'classic-black-abaya')->first();
            $variant = $abaya?->variants()->where('name', 'like', '%S%')->first();
            $price = $variant ? (float) $variant->price : ($abaya ? (float) $abaya->final_price : 120.00);

            $order = Order::create([
                'order_number'      => 'ORD-PENDPAY1',
                'user_id'           => $userWithOrders->id,
                'status'            => 'pending_payment',
                'payment_status'    => 'unpaid',
                'subtotal'          => $price,
                'tax'               => round($price * 0.15, 2), // 15% VAT
                'shipping_cost'     => 15.00,
                'discount'          => 0.00,
                'total'             => round($price + ($price * 0.15) + 15.00, 2),
                'shipping_address'  => $this->getSAAddress('Mohammad Al-Saudi', '+966501234567'),
                'billing_address'   => $this->getSAAddress('Mohammad Al-Saudi', '+966501234567'),
                'notes'             => 'Waiting for bank transfer or card checkout.',
            ]);

            $this->createOrderItem($order, $abaya, $variant, 1);
        }

        // 2) Order 2: Processing (Paid) with coupon discount
        if ($userWithOrders) {
            $gown = Product::where('slug', 'elegant-evening-gown')->first();
            $gownVariant = $gown?->variants()->where('name', 'like', '%Navy%')->first();
            $gownPrice = $gownVariant ? (float) $gownVariant->price : ($gown ? (float) $gown->final_price : 350.00);

            $necklace = Product::where('slug', '18k-gold-plated-necklace')->first();
            $neckVariant = $necklace?->variants()->where('name', 'like', '%18in%')->first();
            $neckPrice = $neckVariant ? (float) $neckVariant->price : ($necklace ? (float) $necklace->final_price : 45.00);

            $subtotal = $gownPrice + ($neckPrice * 2);
            $coupon = Coupon::where('code', 'SAVE50')->first();
            $discount = 50.00; // Fixed SAVE50
            $total = max(0.0, $subtotal - $discount);

            $order = Order::create([
                'order_number'      => 'ORD-PAIDCOP2',
                'user_id'           => $userWithOrders->id,
                'status'            => 'processing',
                'payment_status'    => 'paid',
                'subtotal'          => $subtotal,
                'tax'               => 0.00,
                'shipping_cost'     => 0.00, // Free shipping
                'discount'          => $discount,
                'total'             => $total,
                'coupon_code'       => 'SAVE50',
                'coupon_id'         => $coupon?->id,
                'shipping_address'  => $this->getSAAddress('Mohammad Al-Saudi', '+966501234567'),
                'billing_address'   => $this->getSAAddress('Mohammad Al-Saudi (Office)', '+966501234567'),
                'notes'             => 'Leave with security guard.',
                'paid_at'           => now()->subDays(2),
            ]);

            $this->createOrderItem($order, $gown, $gownVariant, 1);
            $this->createOrderItem($order, $necklace, $neckVariant, 2);

            // Record Coupon Usage
            if ($coupon) {
                CouponUsage::create([
                    'coupon_id'       => $coupon->id,
                    'user_id'         => $userWithOrders->id,
                    'order_id'        => $order->id,
                    'discount_amount' => $discount,
                ]);
                $coupon->increment('used_count');
            }

            // Create Payment record
            Payment::create([
                'order_id'         => $order->id,
                'transaction_id'   => 'ch_' . Str::random(24),
                'payment_provider' => 'stripe',
                'status'           => 'completed',
                'amount'           => $total,
                'payload'          => ['charge_id' => 'ch_xxx', 'card_brand' => 'visa', 'last4' => '4242'],
            ]);
        }

        // 3) Order 3: Shipped (Paid)
        if ($userWithOrders) {
            $tote = Product::where('slug', 'italian-leather-tote')->first();
            $toteVariant = $tote?->variants()->where('name', 'like', '%Cognac%')->first();
            $totePrice = $toteVariant ? (float) $toteVariant->price : ($tote ? (float) $tote->final_price : 290.00);

            $order = Order::create([
                'order_number'      => 'ORD-SHIPPED3',
                'user_id'           => $userWithOrders->id,
                'status'            => 'shipped',
                'payment_status'    => 'paid',
                'subtotal'          => $totePrice,
                'tax'               => 0.00,
                'shipping_cost'     => 10.00,
                'discount'          => 0.00,
                'total'             => $totePrice + 10.00,
                'shipping_address'  => $this->getSAAddress('Mohammad Al-Saudi', '+966501234567'),
                'billing_address'   => $this->getSAAddress('Mohammad Al-Saudi', '+966501234567'),
                'paid_at'           => now()->subDays(3),
            ]);

            $this->createOrderItem($order, $tote, $toteVariant, 1);

            Payment::create([
                'order_id'         => $order->id,
                'transaction_id'   => 'ch_' . Str::random(24),
                'payment_provider' => 'stripe',
                'status'           => 'completed',
                'amount'           => $totePrice + 10.00,
                'payload'          => ['charge_id' => 'ch_yyy', 'card_brand' => 'mastercard', 'last4' => '1111'],
            ]);
        }

        // 4) Order 4: Delivered (Paid)
        if ($userWithOrders) {
            $perfume = Product::where('slug', 'royal-oud-perfume')->first();
            $perfVariant = $perfume?->variants()->where('name', 'like', '%100ml%')->first();
            $perfPrice = $perfVariant ? (float) $perfVariant->price : ($perfume ? (float) $perfume->final_price : 180.00);

            $order = Order::create([
                'order_number'      => 'ORD-DELIVRD4',
                'user_id'           => $userWithOrders->id,
                'status'            => 'delivered',
                'payment_status'    => 'paid',
                'subtotal'          => $perfPrice,
                'tax'               => 0.00,
                'shipping_cost'     => 0.00,
                'discount'          => 0.00,
                'total'             => $perfPrice,
                'shipping_address'  => $this->getSAAddress('Mohammad Al-Saudi', '+966501234567'),
                'billing_address'   => $this->getSAAddress('Mohammad Al-Saudi', '+966501234567'),
                'paid_at'           => now()->subDays(10),
            ]);

            $this->createOrderItem($order, $perfume, $perfVariant, 1);

            Payment::create([
                'order_id'         => $order->id,
                'transaction_id'   => 'ch_' . Str::random(24),
                'payment_provider' => 'stripe',
                'status'           => 'completed',
                'amount'           => $perfPrice,
                'payload'          => ['charge_id' => 'ch_zzz'],
            ]);
        }

        // 5) Order 5: Cancelled for userCancelled (with Cancellation Request)
        if ($userCancelled) {
            $macbook = Product::where('slug', 'macbook-pro-m3-max')->first();
            $price = $macbook ? (float) $macbook->final_price : 2999.00;

            $order = Order::create([
                'order_number'      => 'ORD-CANCELD5',
                'user_id'           => $userCancelled->id,
                'status'            => 'cancelled',
                'payment_status'    => 'unpaid',
                'subtotal'          => $price,
                'tax'               => 0.00,
                'shipping_cost'     => 0.00,
                'discount'          => 0.00,
                'total'             => $price,
                'shipping_address'  => $this->getSAAddress('Khaled Omar', '+966507654321'),
                'billing_address'   => $this->getSAAddress('Khaled Omar', '+966507654321'),
                'cancelled_at'      => now()->subDays(1),
            ]);

            $this->createOrderItem($order, $macbook, null, 1);

            // Create approved cancellation request
            OrderCancellationRequest::create([
                'order_id'   => $order->id,
                'user_id'    => $userCancelled->id,
                'reason'     => 'I ordered by mistake and need to buy a different specification.',
                'status'     => 'accepted', // accepted / rejected / pending
                'admin_id'   => 1,
                'admin_note' => 'Approved as requested.',
                'decided_at' => now()->subHours(12),
            ]);
        }

        // 6) Order 6: Refunded for userCancelled
        if ($userCancelled) {
            $iphone = Product::where('slug', 'iphone-15-pro-max')->first();
            $iphVariant = $iphone?->variants()->where('name', 'like', '%256GB%')->first();
            $price = $iphVariant ? (float) $iphVariant->price : ($iphone ? (float) $iphone->final_price : 1199.00);

            $order = Order::create([
                'order_number'      => 'ORD-REFUNDD6',
                'user_id'           => $userCancelled->id,
                'status'            => 'refunded',
                'payment_status'    => 'refunded',
                'subtotal'          => $price,
                'tax'               => 0.00,
                'shipping_cost'     => 0.00,
                'discount'          => 0.00,
                'total'             => $price,
                'shipping_address'  => $this->getSAAddress('Khaled Omar', '+966507654321'),
                'billing_address'   => $this->getSAAddress('Khaled Omar', '+966507654321'),
                'paid_at'           => now()->subDays(5),
                'refunded_at'       => now()->subDays(2),
            ]);

            $this->createOrderItem($order, $iphone, $iphVariant, 1);

            // Original Payment
            $payment = Payment::create([
                'order_id'         => $order->id,
                'transaction_id'   => 'ch_' . Str::random(24),
                'payment_provider' => 'stripe',
                'status'           => 'completed',
                'amount'           => $price,
                'payload'          => ['charge_id' => 'ch_refund_orig'],
            ]);

            // Refund Payment log
            Payment::create([
                'order_id'         => $order->id,
                'transaction_id'   => 're_' . Str::random(24),
                'payment_provider' => 'stripe',
                'status'           => 'failed', // refund represents standard COD or other log
                'amount'           => -$price,
                'payload'          => ['refund_id' => 're_123', 'original_charge' => 'ch_refund_orig'],
            ]);
        }

        // 7) 10 orders for userMany (Pagination, Date Sorting)
        if ($userMany) {
            $perfume = Product::where('slug', 'royal-oud-perfume')->first();
            $scarf = Product::where('slug', 'premium-cotton-scarf')->first();
            $sandals = Product::where('slug', 'leather-strappy-sandals')->first();

            $productsList = [$perfume, $scarf, $sandals];
            $statuses = ['pending', 'pending_payment', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'];

            for ($i = 1; $i <= 10; $i++) {
                $prod = $productsList[$i % 3];
                if (!$prod) continue;
                
                $price = (float) $prod->final_price;
                $qty = ($i % 2) + 1;
                $subtotal = $price * $qty;
                $status = $statuses[$i % count($statuses)];

                $paymentStatus = 'paid';
                if ($status === 'pending' || $status === 'pending_payment' || $status === 'cancelled') {
                    $paymentStatus = 'unpaid';
                } elseif ($status === 'refunded') {
                    $paymentStatus = 'refunded';
                }

                $order = Order::create([
                    'order_number'      => 'ORD-PAGINAT' . $i,
                    'user_id'           => $userMany->id,
                    'status'            => $status,
                    'payment_status'    => $paymentStatus,
                    'subtotal'          => $subtotal,
                    'tax'               => 0.00,
                    'shipping_cost'     => 10.00,
                    'discount'          => 0.00,
                    'total'             => $subtotal + 10.00,
                    'shipping_address'  => $this->getSAAddress('Fatima Al-Harbi', '+966507654321'),
                    'billing_address'   => $this->getSAAddress('Fatima Al-Harbi', '+966507654321'),
                    'created_at'        => now()->subMonths($i)->subDays($i),
                ]);

                $this->createOrderItem($order, $prod, null, $qty);
            }
        }
    }

    /**
     * Helper to assemble shipping_address array.
     */
    private function getSAAddress(string $name, string $phone): array
    {
        return [
            'name'        => $name,
            'line1'       => 'King Fahd Rd, Sector 5',
            'city'        => 'Riyadh',
            'country'     => 'SA',
            'phone'       => $phone,
            'postal_code' => '12211',
            'state'       => 'Riyadh Region',
        ];
    }

    /**
     * Helper to safely create an OrderItem.
     */
    private function createOrderItem(Order $order, ?Product $product, ?ProductVariant $variant, int $qty): void
    {
        if (!$product) {
            return;
        }

        $price = $variant ? (float) $variant->price : (float) $product->final_price;

        OrderItem::create([
            'order_id'           => $order->id,
            'product_id'         => $product->id,
            'variant_id'         => $variant?->id,
            'product_name'       => $product->name,
            'variant_name'       => $variant?->name,
            'variant_attributes' => $variant?->attributes, // JSON snapshot
            'sku'                => $variant?->sku ?? $product->sku,
            'price'              => $price,
            'quantity'           => $qty,
            'total'              => $price * $qty,
        ]);
    }
}
