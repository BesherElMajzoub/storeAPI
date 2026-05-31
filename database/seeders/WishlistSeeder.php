<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use App\Models\WishlistItem;
use Illuminate\Database\Seeder;

class WishlistSeeder extends Seeder
{
    public function run(): void
    {
        $userWishlist = User::where('email', 'user_wishlist@store.com')->first();
        $userWithOrders = User::where('email', 'user_with_orders@store.com')->first();

        $abaya = Product::where('slug', 'classic-black-abaya')->first();
        $gown = Product::where('slug', 'elegant-evening-gown')->first();
        $oosAbaya = Product::where('slug', 'out-of-stock-abaya')->first();
        $tote = Product::where('slug', 'italian-leather-tote')->first();
        $necklace = Product::where('slug', '18k-gold-plated-necklace')->first();

        // 1) User with many wishlist items (including out of stock)
        if ($userWishlist) {
            $wishlistProducts = [$abaya, $gown, $oosAbaya, $tote, $necklace];

            foreach ($wishlistProducts as $prod) {
                if ($prod) {
                    WishlistItem::firstOrCreate([
                        'user_id'    => $userWishlist->id,
                        'product_id' => $prod->id,
                    ]);
                }
            }
        }

        // 2) User with few wishlist items
        if ($userWithOrders) {
            if ($abaya) {
                WishlistItem::firstOrCreate([
                    'user_id'    => $userWithOrders->id,
                    'product_id' => $abaya->id,
                ]);
            }
            if ($necklace) {
                WishlistItem::firstOrCreate([
                    'user_id'    => $userWithOrders->id,
                    'product_id' => $necklace->id,
                ]);
            }
        }
    }
}
