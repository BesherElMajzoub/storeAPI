<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ReviewService
{
    /**
     * Create a new review for a product by the authenticated user.
     *
     * @throws \Exception if user has already reviewed this product
     */
    public function create(User $user, Product $product, array $data, string $ipAddress = ''): Review
    {
        // Check: one review per user per product
        if ($this->hasReviewed($user->id, $product->id)) {
            throw new \Exception('You have already reviewed this product.');
        }

        // Check: daily rate limit (max 5 reviews per day)
        if ($this->exceedsDailyLimit($user->id)) {
            throw new \Exception('You have reached the daily review limit. Please try again tomorrow.');
        }

        $review = Review::create([
            'user_id'              => $user->id,
            'product_id'           => $product->id,
            'order_id'             => $this->findVerifiedPurchaseOrderId($user, $product->id),
            'rating'               => $data['rating'],
            'comment'              => $data['comment'] ?? null,
            'is_approved'          => false, // All reviews go through moderation
            'is_verified_purchase' => $this->isVerifiedPurchase($user, $product->id),
            'ip_address'           => $ipAddress,
        ]);

        Log::info('New review submitted', [
            'user_id'    => $user->id,
            'product_id' => $product->id,
            'review_id'  => $review->id,
        ]);

        return $review;
    }

    /**
     * Update an existing review.
     * Resets approval status on edit — re-queues for moderation.
     */
    public function update(Review $review, array $data): Review
    {
        $review->update([
            'rating'      => $data['rating'] ?? $review->rating,
            'comment'     => $data['comment'] ?? $review->comment,
            'is_approved' => false, // Re-moderate after edit
        ]);

        return $review->fresh();
    }

    /**
     * Delete a review and recalculate product rating.
     */
    public function delete(Review $review): void
    {
        $productId = $review->product_id;
        $review->delete();
        // Observer handles rating recalculation
    }

    /**
     * Admin: approve or reject a review.
     */
    public function moderate(Review $review, string $action, ?string $adminNote = null): Review
    {
        $review->update([
            'is_approved' => ($action === 'approve'),
            'admin_note'  => $adminNote,
        ]);

        Log::info('Review moderated', [
            'review_id'  => $review->id,
            'action'     => $action,
            'product_id' => $review->product_id,
        ]);

        return $review->fresh();
    }

    /**
     * Check if the user has already reviewed this product.
     */
    public function hasReviewed(int $userId, int $productId): bool
    {
        return Review::where('user_id', $userId)
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * Check if user has a delivered order containing this product.
     */
    public function isVerifiedPurchase(User $user, int $productId): bool
    {
        return $user->orders()
            ->where('status', 'delivered')
            ->whereHas('items', fn($q) => $q->where('product_id', $productId))
            ->exists();
    }

    /**
     * Get the order_id for a verified purchase (for linking review to order).
     */
    private function findVerifiedPurchaseOrderId(User $user, int $productId): ?int
    {
        $order = $user->orders()
            ->where('status', 'delivered')
            ->whereHas('items', fn($q) => $q->where('product_id', $productId))
            ->latest()
            ->first();

        return $order?->id;
    }

    /**
     * Enforce a max of 5 reviews per user per day (anti-spam).
     */
    private function exceedsDailyLimit(int $userId): bool
    {
        $count = Review::where('user_id', $userId)
            ->whereDate('created_at', today())
            ->count();

        return $count >= 5;
    }
}
