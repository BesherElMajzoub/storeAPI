<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderCancellationRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OrderCancellationRequestSeeder extends Seeder
{
    public function run(): void
    {
        // Disable order event dispatchers (observers) to prevent decrementing stock
        Order::unsetEventDispatcher();

        $userCancelled = User::where('email', 'user_cancelled_orders@store.com')->first();
        $admin = User::where('email', 'admin@store.com')->first();

        if (!$userCancelled) {
            return;
        }

        // 1) Seed a Pending Cancellation Request
        $abaya = Product::where('slug', 'classic-black-abaya')->first();
        $variant = $abaya?->variants()->first();
        $price = $variant ? (float) $variant->price : ($abaya ? (float) $abaya->final_price : 120.00);

        $pendingOrder = Order::create([
            'order_number'      => 'ORD-CANCPEND1',
            'user_id'           => $userCancelled->id,
            'status'            => 'processing',
            'payment_status'    => 'paid',
            'subtotal'          => $price,
            'tax'               => round($price * 0.15, 2),
            'shipping_cost'     => 15.00,
            'discount'          => 0.00,
            'total'             => round($price + ($price * 0.15) + 15.00, 2),
            'shipping_address'  => $this->getSAAddress('Khaled Omar', '+966507654321'),
            'billing_address'   => $this->getSAAddress('Khaled Omar', '+966507654321'),
            'notes'             => 'Please cancel as soon as possible.',
            'paid_at'           => now()->subHours(4),
            'created_at'        => now()->subHours(5),
        ]);

        $this->createOrderItem($pendingOrder, $abaya, $variant, 1);

        OrderCancellationRequest::create([
            'order_id'   => $pendingOrder->id,
            'user_id'    => $userCancelled->id,
            'reason'     => 'I found a better price elsewhere and decided not to proceed.',
            'status'     => 'pending',
            'admin_id'   => null,
            'admin_note' => null,
            'decided_at' => null,
            'created_at' => now()->subHours(3),
        ]);

        // 2) Seed a Rejected Cancellation Request
        $perfume = Product::where('slug', 'royal-oud-perfume')->first();
        $perfVariant = $perfume?->variants()->first();
        $perfPrice = $perfVariant ? (float) $perfVariant->price : ($perfume ? (float) $perfume->final_price : 180.00);

        $rejectedOrder = Order::create([
            'order_number'      => 'ORD-CANCREJ2',
            'user_id'           => $userCancelled->id,
            'status'            => 'processing',
            'payment_status'    => 'paid',
            'subtotal'          => $perfPrice,
            'tax'               => round($perfPrice * 0.15, 2),
            'shipping_cost'     => 0.00,
            'discount'          => 0.00,
            'total'             => round($perfPrice + ($perfPrice * 0.15), 2),
            'shipping_address'  => $this->getSAAddress('Khaled Omar', '+966507654321'),
            'billing_address'   => $this->getSAAddress('Khaled Omar', '+966507654321'),
            'notes'             => 'Gift wrap if possible.',
            'paid_at'           => now()->subDays(2),
            'created_at'        => now()->subDays(2),
        ]);

        $this->createOrderItem($rejectedOrder, $perfume, $perfVariant, 1);

        OrderCancellationRequest::create([
            'order_id'   => $rejectedOrder->id,
            'user_id'    => $userCancelled->id,
            'reason'     => 'Changed my mind, want to change the shipping address.',
            'status'     => 'rejected',
            'admin_id'   => $admin?->id ?? 1,
            'admin_note' => 'Your order has already been handed over to the shipping carrier. Address details cannot be changed at this stage. Please contact support for redirection assistance.',
            'decided_at' => now()->subHours(20),
            'created_at' => now()->subHours(24),
        ]);
    }

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
            'variant_attributes' => $variant?->attributes,
            'sku'                => $variant?->sku ?? $product->sku,
            'price'              => $price,
            'quantity'           => $qty,
            'total'              => $price * $qty,
        ]);
    }
}
