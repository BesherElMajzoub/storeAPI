<?php

namespace App\Services;

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class OrderInventoryService
{
    /**
     * Lock, validate, quote and reserve all requested items.
     * Must be called from an existing database transaction.
     *
     * @return array{subtotal: float, items: array<int, array<string, mixed>>}
     */
    public function quoteAndReserve(array $items): array
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Inventory reservation requires a database transaction.');
        }

        usort($items, fn (array $a, array $b) => [$a['product_id'], $a['variant_id'] ?? 0] <=> [$b['product_id'], $b['variant_id'] ?? 0]);

        $subtotal = 0.0;
        $quotedItems = [];

        foreach ($items as $item) {
            $quantity = (int) $item['quantity'];
            $product = Product::query()->whereKey($item['product_id'])->lockForUpdate()->firstOrFail();

            if ($product->status !== 'published' || ! $product->in_stock || $product->stock_qty < $quantity) {
                throw new InsufficientStockException('items', "Insufficient stock for product {$product->id}.");
            }

            $variant = null;
            if (! empty($item['variant_id'])) {
                $variant = ProductVariant::query()
                    ->whereKey($item['variant_id'])
                    ->where('product_id', $product->id)
                    ->lockForUpdate()
                    ->first();

                if (! $variant) {
                    throw new InsufficientStockException('items', 'The selected variant does not belong to the product.');
                }

                if ($variant->stock_qty < $quantity) {
                    throw new InsufficientStockException('items', "Insufficient stock for variant {$variant->id}.");
                }
            }

            $price = (float) ($variant?->price ?? $product->final_price);
            $lineTotal = round($price * $quantity, 2);
            $subtotal = round($subtotal + $lineTotal, 2);

            $productQty = $product->stock_qty - $quantity;
            $product->forceFill(['stock_qty' => $productQty, 'in_stock' => $productQty > 0])->saveQuietly();
            if ($variant) {
                $variant->forceFill(['stock_qty' => $variant->stock_qty - $quantity])->saveQuietly();
            }

            $quotedItems[] = [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'variant_id' => $variant?->id,
                'variant_name' => $variant?->name,
                'variant_attributes' => $variant?->attributes,
                'sku' => $variant?->sku ?? $product->sku,
                'price' => $price,
                'quantity' => $quantity,
                'total' => $lineTotal,
            ];
        }

        return ['subtotal' => $subtotal, 'items' => $quotedItems];
    }

    public function reserveExistingOrder(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->stock_reserved_at && ! $lockedOrder->stock_released_at) {
                return;
            }

            $this->quoteAndReserve($lockedOrder->items->map(fn ($item) => [
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'quantity' => $item->quantity,
            ])->all());

            $lockedOrder->forceFill(['stock_reserved_at' => now(), 'stock_released_at' => null])->saveQuietly();
        });
    }

    public function release(Order $order): void
    {
        DB::transaction(function () use ($order): void {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $lockedOrder || ! $lockedOrder->stock_reserved_at || $lockedOrder->stock_released_at) {
                return;
            }

            foreach ($lockedOrder->items()->orderBy('product_id')->get() as $item) {
                $product = Product::withTrashed()->whereKey($item->product_id)->lockForUpdate()->first();
                if ($product) {
                    $qty = $product->stock_qty + $item->quantity;
                    $product->forceFill(['stock_qty' => $qty, 'in_stock' => $qty > 0])->saveQuietly();
                }

                if ($item->variant_id) {
                    $variant = ProductVariant::query()->whereKey($item->variant_id)->lockForUpdate()->first();
                    $variant?->forceFill(['stock_qty' => $variant->stock_qty + $item->quantity])->saveQuietly();
                }
            }

            $lockedOrder->forceFill(['stock_released_at' => now()])->saveQuietly();
        });
    }
}
