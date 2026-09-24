<?php

namespace Database\Seeders;

use App\Models\Campaign;
use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Payment;
use App\Models\Post;
use App\Models\Product;
use App\Models\Setting;
use App\Models\ShippingRateQuote;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class DemoCommerceSeeder extends DemoSeeder
{
    public const ORDER_COUNT = 42;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $coupons = $this->seedCoupons();
        $this->seedCampaignsAndContent();
        $this->seedOrders($coupons);
        $this->seedUnconsumedQuotes();

        $this->command?->info('Demo commerce: 10 coupons, 42 orders, payments, labels, tracking, refunds, and cancellation states.');
    }

    private function seedCoupons(): array
    {
        $definitions = [
            ['code' => 'DEMO-WELCOME-15', 'type' => 'percentage', 'value' => 15, 'minimum_order_amount' => null, 'maximum_discount_amount' => 40, 'usage_limit' => 500, 'used_count' => 0, 'usage_limit_per_user' => 1, 'starts_at' => now()->subMonth(), 'expires_at' => now()->addYear(), 'is_active' => true],
            ['code' => 'DEMO-SAVE-25', 'type' => 'fixed', 'value' => 25, 'minimum_order_amount' => 100, 'maximum_discount_amount' => null, 'usage_limit' => 100, 'used_count' => 0, 'usage_limit_per_user' => 3, 'starts_at' => now()->subWeek(), 'expires_at' => now()->addMonths(6), 'is_active' => true],
            ['code' => 'DEMO-VIP-30', 'type' => 'percentage', 'value' => 30, 'minimum_order_amount' => 250, 'maximum_discount_amount' => 120, 'usage_limit' => 25, 'used_count' => 0, 'usage_limit_per_user' => 1, 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'is_active' => true],
            ['code' => 'DEMO-NO-LIMIT', 'type' => 'fixed', 'value' => 5, 'minimum_order_amount' => null, 'maximum_discount_amount' => null, 'usage_limit' => null, 'used_count' => 0, 'usage_limit_per_user' => null, 'starts_at' => null, 'expires_at' => null, 'is_active' => true],
            ['code' => 'DEMO-MIN-500', 'type' => 'fixed', 'value' => 75, 'minimum_order_amount' => 500, 'maximum_discount_amount' => null, 'usage_limit' => 50, 'used_count' => 0, 'usage_limit_per_user' => 2, 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonths(3), 'is_active' => true],
            ['code' => 'DEMO-EXPIRED', 'type' => 'percentage', 'value' => 10, 'minimum_order_amount' => null, 'maximum_discount_amount' => null, 'usage_limit' => 100, 'used_count' => 12, 'usage_limit_per_user' => null, 'starts_at' => now()->subYear(), 'expires_at' => now()->subDay(), 'is_active' => true],
            ['code' => 'DEMO-FUTURE', 'type' => 'percentage', 'value' => 20, 'minimum_order_amount' => null, 'maximum_discount_amount' => 50, 'usage_limit' => 100, 'used_count' => 0, 'usage_limit_per_user' => null, 'starts_at' => now()->addMonth(), 'expires_at' => now()->addYear(), 'is_active' => true],
            ['code' => 'DEMO-DISABLED', 'type' => 'fixed', 'value' => 20, 'minimum_order_amount' => null, 'maximum_discount_amount' => null, 'usage_limit' => 100, 'used_count' => 0, 'usage_limit_per_user' => null, 'starts_at' => now()->subDay(), 'expires_at' => now()->addYear(), 'is_active' => false],
            ['code' => 'DEMO-EXHAUSTED', 'type' => 'fixed', 'value' => 10, 'minimum_order_amount' => null, 'maximum_discount_amount' => null, 'usage_limit' => 5, 'used_count' => 5, 'usage_limit_per_user' => null, 'starts_at' => now()->subDay(), 'expires_at' => now()->addYear(), 'is_active' => true],
            ['code' => 'DEMO-100-PERCENT-CAPPED', 'type' => 'percentage', 'value' => 100, 'minimum_order_amount' => 50, 'maximum_discount_amount' => 35, 'usage_limit' => 10, 'used_count' => 0, 'usage_limit_per_user' => 1, 'starts_at' => now()->subDay(), 'expires_at' => now()->addWeek(), 'is_active' => true],
        ];

        $coupons = [];
        foreach ($definitions as $definition) {
            $coupon = Coupon::updateOrCreate(['code' => $definition['code']], $definition);
            $coupons[$coupon->code] = $coupon;
        }

        return $coupons;
    }

    private function seedCampaignsAndContent(): void
    {
        $campaigns = [
            ['name' => 'Demo Autumn Launch', 'type' => 'flash_sale', 'conditions' => ['discount_percent' => 20, 'category' => 'demo-women-dresses'], 'starts_at' => now()->subDays(2), 'expires_at' => now()->addDays(5), 'is_active' => true],
            ['name' => 'Demo Buy One Get One', 'type' => 'bogo', 'conditions' => ['buy' => 1, 'get' => 1, 'category' => 'demo-accessories-scarves'], 'starts_at' => now()->subDay(), 'expires_at' => now()->addWeek(), 'is_active' => true],
            ['name' => 'Demo Scheduled Weekend', 'type' => 'flash_sale', 'conditions' => ['discount_percent' => 12], 'starts_at' => now()->addDays(2), 'expires_at' => now()->addDays(4), 'is_active' => true],
            ['name' => 'Demo Expired Campaign', 'type' => 'flash_sale', 'conditions' => ['discount_percent' => 50], 'starts_at' => now()->subMonth(), 'expires_at' => now()->subWeek(), 'is_active' => true],
            ['name' => 'Demo Disabled Campaign', 'type' => 'collection', 'conditions' => ['tag' => 'premium'], 'starts_at' => null, 'expires_at' => null, 'is_active' => false],
            ['name' => 'Demo Free Shipping Banner', 'type' => 'banner', 'conditions' => ['minimum' => 250, 'display_only' => true], 'starts_at' => now()->subDay(), 'expires_at' => now()->addMonth(), 'is_active' => true],
        ];
        foreach ($campaigns as $campaign) {
            Campaign::updateOrCreate(['name' => $campaign['name']], $campaign);
        }

        $settings = [
            ['group' => 'general', 'key' => 'demo_store_notice', 'value' => 'Frontend demonstration environment — no real orders are fulfilled.', 'type' => 'string'],
            ['group' => 'general', 'key' => 'demo_support_email', 'value' => 'support@demo.test', 'type' => 'string'],
            ['group' => 'catalogue', 'key' => 'demo_low_stock_threshold', 'value' => '3', 'type' => 'integer'],
            ['group' => 'catalogue', 'key' => 'demo_show_out_of_stock', 'value' => 'true', 'type' => 'boolean'],
            ['group' => 'shipping', 'key' => 'demo_supported_countries', 'value' => json_encode(['US']), 'type' => 'json'],
            ['group' => 'shipping', 'key' => 'demo_quote_ttl_minutes', 'value' => '15', 'type' => 'integer'],
            ['group' => 'payment', 'key' => 'demo_currency', 'value' => 'USD', 'type' => 'string'],
            ['group' => 'seo', 'key' => 'demo_default_title', 'value' => 'Otantik Queen Demo Store', 'type' => 'string'],
        ];
        foreach ($settings as $setting) {
            Setting::updateOrCreate(['key' => $setting['key']], $setting);
        }

        $author = User::where('email', 'owner@demo.test')->firstOrFail();
        for ($index = 1; $index <= 12; $index++) {
            $status = $index % 5 === 0 ? 'draft' : ($index % 4 === 0 ? 'scheduled' : 'published');
            Post::updateOrCreate(
                ['slug' => sprintf('demo-style-journal-%02d', $index)],
                [
                    'title' => sprintf('Demo Style Journal Article %02d%s', $index, $index % 3 === 0 ? ' — مقال تجريبي' : ''),
                    'content' => str_repeat('This is long-form demonstration content for typography, reading width, headings, and responsive layouts. ', ($index % 5) + 2),
                    'image' => $index % 4 === 0 ? null : '/storage/demo/posts/post-'.$index.'.png',
                    'status' => $status,
                    'published_at' => $status === 'published' ? now()->subDays($index) : ($status === 'scheduled' ? now()->addDays($index) : null),
                    'author_id' => $author->id,
                    'meta_title' => 'Demo Style Article '.$index,
                    'meta_description' => 'SEO metadata for demonstration journal article '.$index.'.',
                ]
            );
        }
    }

    private function seedOrders(array $coupons): void
    {
        $customers = User::whereHas('roles', fn ($query) => $query->where('name', 'User'))
            ->where('is_active', true)
            ->whereNotNull('email_verified_at')
            ->where('email', '!=', 'customer.empty@demo.test')
            ->orderBy('id')
            ->get();
        $products = Product::published()->with('variants')->orderBy('id')->get();
        $admin = User::where('email', 'admin@demo.test')->firstOrFail();
        $trackingStatuses = ['pre_transit', 'in_transit', 'out_for_delivery', 'available_for_pickup', 'delivered', 'return_to_sender', 'failure', 'cancelled', 'error', 'unknown'];

        for ($index = 1; $index <= self::ORDER_COUNT; $index++) {
            $user = $customers[($index - 1) % $customers->count()];
            $scenario = $this->orderScenario($index, $trackingStatuses);
            $lineCount = ($index % 3) + 1;
            $lines = [];
            $subtotal = 0.0;

            for ($line = 0; $line < $lineCount; $line++) {
                $product = $products[(($index * 3) + $line) % $products->count()];
                $variant = $product->variants->isNotEmpty()
                    ? $product->variants[(($index + $line) % $product->variants->count())]
                    : null;
                $quantity = ($index + $line) % 3 === 0 ? 2 : 1;
                $price = (float) ($variant?->price ?? $product->final_price);
                $subtotal += $price * $quantity;
                $lines[] = compact('product', 'variant', 'quantity', 'price');
            }

            $coupon = $index % 5 === 0 ? $coupons['DEMO-WELCOME-15'] : null;
            $discount = $coupon ? min(round($subtotal * 0.15, 2), 40.0) : 0.0;
            $shipping = round(6.95 + (($index % 5) * 2.25), 2);
            $tax = round($subtotal * 0.0725, 2);
            $total = round(max(0, $subtotal + $tax + $shipping - $discount), 2);
            $address = $user->addresses()->default()->first() ?? $user->addresses()->first();
            $shippingAddress = $this->orderAddress($address, $user);
            $createdAt = now()->subDays($index % 34)->subMinutes($index * 7);
            $orderNumber = sprintf('DEMO-%06d', $index);
            $rateId = sprintf('rate_demo_%06d', $index);
            $shipmentId = sprintf('shp_demo_%06d', $index);
            $trackingNumber = $scenario['has_shipment'] ? sprintf('940000000000%010d', $index) : null;
            $refundedAmount = $scenario['partial_refund'] ? min(25, $total) : ($scenario['status'] === 'refunded' ? $total : 0);

            $order = Order::withoutEvents(fn () => Order::updateOrCreate(
                ['order_number' => $orderNumber],
                [
                    'user_id' => $user->id,
                    'coupon_id' => $coupon?->id,
                    'status' => $scenario['status'],
                    'payment_status' => $scenario['payment_status'],
                    'subtotal' => $subtotal,
                    'tax' => $tax,
                    'shipping_cost' => $shipping,
                    'discount' => $discount,
                    'refunded_amount' => $refundedAmount,
                    'total' => $total,
                    'coupon_code' => $coupon?->code,
                    'shipping_address' => $shippingAddress,
                    'billing_address' => $index % 4 === 0 ? [...$shippingAddress, 'line2' => 'Billing department'] : $shippingAddress,
                    'notes' => $index % 6 === 0 ? 'A long customer note for wrapping tests. Please call once, avoid ringing twice, and leave the parcel with reception only after confirming the recipient name.' : null,
                    'stripe_session_id' => 'cs_demo_'.$index,
                    'stripe_payment_intent_id' => $scenario['payment_status'] === 'unpaid' ? null : 'pi_demo_'.$index,
                    'paid_at' => in_array($scenario['payment_status'], ['paid', 'refunded'], true) ? $createdAt->copy()->addMinutes(8) : null,
                    'cancelled_at' => $scenario['status'] === 'cancelled' ? $createdAt->copy()->addHours(2) : null,
                    'refunded_at' => $refundedAmount > 0 ? $createdAt->copy()->addDays(2) : null,
                    'stock_reserved_at' => in_array($scenario['status'], ['processing', 'shipped', 'delivered'], true) ? $createdAt : null,
                    'stock_released_at' => in_array($scenario['status'], ['cancelled', 'refunded'], true) ? $createdAt->copy()->addHours(3) : null,
                    'easypost_shipment_id' => $shipmentId,
                    'shipping_rate_id' => $rateId,
                    'shipping_carrier' => $index % 2 === 0 ? 'USPS' : 'UPS',
                    'shipping_service' => $index % 2 === 0 ? 'Priority' : 'Ground',
                    'tracking_number' => $trackingNumber,
                    'shipment_status' => $scenario['shipment_status'],
                    'tracking_url' => $trackingNumber ? 'https://tools.usps.com/go/TrackConfirmAction?tLabels='.$trackingNumber : null,
                    'label_url' => $trackingNumber ? 'https://labels.demo.test/'.$orderNumber.'.pdf' : null,
                    'shipped_at' => $trackingNumber ? $createdAt->copy()->addDay() : null,
                    'estimated_delivery' => $trackingNumber ? $createdAt->copy()->addDays(5)->toDateString() : null,
                    'tracking_events' => $trackingNumber ? $this->trackingEvents($scenario['shipment_status'], $createdAt) : null,
                ]
            ));
            DB::table('orders')->where('id', $order->id)->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

            $order->items()->delete();
            foreach ($lines as $line) {
                $lineTotal = round($line['price'] * $line['quantity'], 2);
                $order->items()->create([
                    'product_id' => $line['product']->id,
                    'variant_id' => $line['variant']?->id,
                    'product_name' => $line['product']->name,
                    'variant_name' => $line['variant']?->name,
                    'variant_attributes' => $line['variant']?->attributes,
                    'sku' => $line['variant']?->sku ?? $line['product']->sku,
                    'price' => $line['price'],
                    'quantity' => $line['quantity'],
                    'total' => $lineTotal,
                ]);
            }

            Payment::updateOrCreate(
                ['order_id' => $order->id],
                [
                    'transaction_id' => $scenario['payment_status'] === 'unpaid' ? null : 'txn_demo_'.$index,
                    'payment_provider' => 'stripe',
                    'status' => $scenario['payment_status'] === 'unpaid'
                        ? ($scenario['status'] === 'cancelled' ? 'failed' : 'pending')
                        : 'completed',
                    'amount' => $total,
                    'payload' => [
                        'demo' => true,
                        'card_brand' => $index % 2 === 0 ? 'visa' : 'mastercard',
                        'last4' => $index % 2 === 0 ? '4242' : '4444',
                    ],
                ]
            );

            ShippingRateQuote::updateOrCreate(
                ['rate_id' => $rateId],
                [
                    'shipment_id' => $shipmentId,
                    'carrier' => $index % 2 === 0 ? 'USPS' : 'UPS',
                    'service' => $index % 2 === 0 ? 'Priority' : 'Ground',
                    'amount' => $shipping,
                    'currency' => 'USD',
                    'eta_days' => 3 + ($index % 4),
                    'address_hash' => hash('sha256', json_encode($shippingAddress)),
                    'items_hash' => hash('sha256', json_encode(array_map(fn ($line) => [$line['product']->id, $line['variant']?->id, $line['quantity']], $lines))),
                    'parcel_hash' => hash('sha256', 'demo-parcel-'.$index),
                    'expires_at' => $createdAt->copy()->addMinutes(15),
                    'consumed_at' => $createdAt,
                    'order_id' => $order->id,
                ]
            );

            if ($coupon) {
                CouponUsage::updateOrCreate(
                    ['coupon_id' => $coupon->id, 'user_id' => $user->id, 'order_id' => $order->id],
                    ['discount_amount' => $discount]
                );
            }

            if ($scenario['status'] === 'cancelled' || $index % 14 === 0) {
                $requestStatus = $index % 3 === 0 ? 'accepted' : ($index % 3 === 1 ? 'pending' : 'rejected');
                OrderCancellationRequest::updateOrCreate(
                    ['order_id' => $order->id],
                    [
                        'user_id' => $user->id,
                        'reason' => 'Demo cancellation request used to verify status badges, long text wrapping, and admin decision actions.',
                        'status' => $requestStatus,
                        'admin_id' => $requestStatus === 'pending' ? null : $admin->id,
                        'admin_note' => $requestStatus === 'rejected' ? 'The order was already handed to the carrier.' : ($requestStatus === 'accepted' ? 'Approved in the demo dataset.' : null),
                        'decided_at' => $requestStatus === 'pending' ? null : $createdAt->copy()->addHours(4),
                    ]
                );
            }

            if ($refundedAmount > 0) {
                WalletTransaction::updateOrCreate(
                    ['user_id' => $user->id, 'order_id' => $order->id, 'reason' => 'Demo order refund'],
                    ['type' => 'credit', 'amount' => $refundedAmount, 'admin_id' => $admin->id]
                );
            }
        }

        foreach ($coupons as $coupon) {
            if (str_starts_with($coupon->code, 'DEMO-') && ! in_array($coupon->code, ['DEMO-EXPIRED', 'DEMO-EXHAUSTED'], true)) {
                $coupon->update(['used_count' => $coupon->usages()->count()]);
            }
        }
    }

    private function orderScenario(int $index, array $trackingStatuses): array
    {
        return match ($index % 10) {
            0 => ['status' => 'pending_payment', 'payment_status' => 'unpaid', 'has_shipment' => false, 'shipment_status' => null, 'partial_refund' => false],
            1 => ['status' => 'pending', 'payment_status' => 'unpaid', 'has_shipment' => false, 'shipment_status' => null, 'partial_refund' => false],
            2 => ['status' => 'processing', 'payment_status' => 'paid', 'has_shipment' => false, 'shipment_status' => 'pre_transit', 'partial_refund' => false],
            3, 4, 9 => [
                'status' => 'shipped',
                'payment_status' => 'paid',
                'has_shipment' => true,
                'shipment_status' => $trackingStatuses[((intdiv($index, 10) * 3) + match ($index % 10) {
                    3 => 0,
                    4 => 1,
                    default => 2,
                }) % count($trackingStatuses)],
                'partial_refund' => false,
            ],
            5 => ['status' => 'delivered', 'payment_status' => 'paid', 'has_shipment' => true, 'shipment_status' => 'delivered', 'partial_refund' => false],
            6 => ['status' => 'cancelled', 'payment_status' => 'failed', 'has_shipment' => false, 'shipment_status' => 'cancelled', 'partial_refund' => false],
            7 => ['status' => 'refunded', 'payment_status' => 'refunded', 'has_shipment' => false, 'shipment_status' => null, 'partial_refund' => false],
            8 => ['status' => 'processing', 'payment_status' => 'paid', 'has_shipment' => false, 'shipment_status' => 'pre_transit', 'partial_refund' => true],
        };
    }

    private function orderAddress($address, User $user): array
    {
        return [
            'name' => $address?->full_name ?? $user->name,
            'line1' => $address?->line1 ?? '123 Main Street',
            'line2' => $address?->line2,
            'city' => $address?->city ?? 'Pasadena',
            'state' => $address?->state ?? 'CA',
            'postal_code' => $address?->postal_code ?? '91101',
            'country' => 'US',
            'phone' => $address?->phone ?? $user->phone,
        ];
    }

    private function trackingEvents(string $status, $createdAt): array
    {
        $events = [
            ['status' => 'pre_transit', 'description' => 'Shipping label created.', 'location' => 'New York, NY, US', 'occurred_at' => $createdAt->copy()->addDay()->toIso8601String()],
            ['status' => 'in_transit', 'description' => 'Package departed the regional distribution center.', 'location' => 'Philadelphia, PA, US', 'occurred_at' => $createdAt->copy()->addDays(2)->toIso8601String()],
        ];
        if ($status !== 'in_transit' && $status !== 'pre_transit') {
            $events[] = ['status' => $status, 'description' => 'Latest deterministic demo tracking event.', 'location' => $status === 'delivered' ? 'Pasadena, CA, US' : null, 'occurred_at' => $createdAt->copy()->addDays(3)->toIso8601String()];
        }

        return array_reverse($events);
    }

    private function seedUnconsumedQuotes(): void
    {
        for ($index = 1; $index <= 6; $index++) {
            ShippingRateQuote::updateOrCreate(
                ['rate_id' => sprintf('rate_demo_available_%02d', $index)],
                [
                    'shipment_id' => sprintf('shp_demo_available_%02d', $index),
                    'carrier' => $index % 2 === 0 ? 'USPS' : 'UPS',
                    'service' => $index % 2 === 0 ? 'Priority' : 'Ground',
                    'amount' => 5 + $index,
                    'currency' => 'USD',
                    'eta_days' => 2 + $index,
                    'address_hash' => hash('sha256', 'demo-address-'.$index),
                    'items_hash' => hash('sha256', 'demo-items-'.$index),
                    'parcel_hash' => hash('sha256', 'demo-parcel-'.$index),
                    'expires_at' => $index <= 3 ? now()->addMinutes(15) : now()->subHour(),
                    'consumed_at' => null,
                    'order_id' => null,
                ]
            );
        }
    }
}
