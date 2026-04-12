<?php

namespace App\Listeners;

use App\Events\WishlistItemAdded;
use App\Events\WishlistItemRemoved;
use App\Models\WishlistEvent;
use Illuminate\Support\Facades\Log;

class RecordWishlistEvent
{
    /**
     * Handle WishlistItemAdded event.
     */
    public function handleAdded(WishlistItemAdded $event): void
    {
        $this->record($event->user->id, $event->product->id, 'added');
    }

    /**
     * Handle WishlistItemRemoved event.
     */
    public function handleRemoved(WishlistItemRemoved $event): void
    {
        $this->record($event->user->id, $event->product->id, 'removed');
    }

    /**
     * Write event record to the database.
     */
    private function record(int $userId, int $productId, string $action): void
    {
        try {
            WishlistEvent::create([
                'user_id'    => $userId,
                'product_id' => $productId,
                'action'     => $action,
            ]);
        } catch (\Throwable $e) {
            // Analytics failures must never break the main flow
            Log::warning('Failed to record wishlist event', [
                'user_id'    => $userId,
                'product_id' => $productId,
                'action'     => $action,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
