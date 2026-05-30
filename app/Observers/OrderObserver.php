<?php

namespace App\Observers;

use App\Models\Order;

class OrderObserver
{
    protected array $decrementedStates = ['processing', 'shipped', 'delivered'];

    public function created(Order $order): void
    {
        if (in_array($order->status, $this->decrementedStates, true)) {
            $this->decrementStock($order);
        }
    }

    public function updated(Order $order): void
    {
        $originalStatus = $order->getOriginal('status');
        $newStatus = $order->status;

        if ($originalStatus === $newStatus) {
            return;
        }

        $wasDecremented = in_array($originalStatus, $this->decrementedStates, true);
        $isDecremented = in_array($newStatus, $this->decrementedStates, true);

        if (!$wasDecremented && $isDecremented) {
            $this->decrementStock($order);
        } elseif ($wasDecremented && !$isDecremented) {
            $this->incrementStock($order);
        }
    }

    protected function decrementStock(Order $order): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            $product = $item->product;
            if ($product) {
                // Decrement product stock
                $newQty = max(0, $product->stock_qty - $item->quantity);
                $product->update([
                    'stock_qty' => $newQty,
                    'in_stock'  => $newQty > 0,
                ]);

                // Decrement variant stock
                if ($item->variant_id) {
                    $variant = $product->variants()->find($item->variant_id);
                    if ($variant) {
                        $newVariantQty = max(0, $variant->stock_qty - $item->quantity);
                        $variant->update(['stock_qty' => $newVariantQty]);
                    }
                }
            }
        }
    }

    protected function incrementStock(Order $order): void
    {
        $order->loadMissing('items.product');

        foreach ($order->items as $item) {
            $product = $item->product;
            if ($product) {
                // Increment product stock
                $newQty = $product->stock_qty + $item->quantity;
                $product->update([
                    'stock_qty' => $newQty,
                    'in_stock'  => $newQty > 0,
                ]);

                // Increment variant stock
                if ($item->variant_id) {
                    $variant = $product->variants()->find($item->variant_id);
                    if ($variant) {
                        $variant->update(['stock_qty' => $variant->stock_qty + $item->quantity]);
                    }
                }
            }
        }
    }
}
