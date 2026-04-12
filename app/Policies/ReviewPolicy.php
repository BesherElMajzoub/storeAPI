<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /**
     * Users can update their own review within 30 days of creation.
     */
    public function update(User $user, Review $review): bool
    {
        if ($review->user_id !== $user->id) {
            return false;
        }

        // 30-day edit window
        return $review->created_at->diffInDays(now()) <= 30;
    }

    /**
     * Owners can delete their own review. Admins can delete any review.
     */
    public function delete(User $user, Review $review): bool
    {
        return $review->user_id === $user->id || $user->hasRole('Admin');
    }
}
