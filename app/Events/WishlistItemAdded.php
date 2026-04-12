<?php

namespace App\Events;

use App\Models\User;
use App\Models\Product;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class WishlistItemAdded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Product $product,
    ) {}
}
