<?php

namespace App\Observers;

use App\Models\Review;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class ReviewObserver
{
    /**
     * Handle the Review "created" event.
     */
    public function created(Review $review): void
    {
        // Only recalculate if the review is approved right away
        if ($review->is_approved) {
            $this->recalculateRating($review->product_id);
        }
    }

    /**
     * Handle the Review "updated" event.
     */
    public function updated(Review $review): void
    {
        // Recalculate if approval status changed or rating changed
        if ($review->wasChanged('is_approved') || $review->wasChanged('rating')) {
            $this->recalculateRating($review->product_id);
        }
    }

    /**
     * Handle the Review "deleted" event.
     */
    public function deleted(Review $review): void
    {
        $this->recalculateRating($review->product_id);
    }

    /**
     * Recalculate the average rating and review count for a product.
     * Only counts approved reviews.
     */
    public function recalculateRating(int $productId): void
    {
        try {
            $stats = Review::where('product_id', $productId)
                ->where('is_approved', true)
                ->selectRaw('AVG(rating) as avg_rating, COUNT(*) as total')
                ->first();

            Product::where('id', $productId)->update([
                'rating'        => round((float) ($stats->avg_rating ?? 0), 2),
                'reviews_count' => (int) ($stats->total ?? 0),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to recalculate product rating', [
                'product_id' => $productId,
                'error'      => $e->getMessage(),
            ]);
        }
    }
}
