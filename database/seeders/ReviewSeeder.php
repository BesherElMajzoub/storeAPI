<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;

class ReviewSeeder extends Seeder
{
    public function run(): void
    {
        $abaya = Product::where('slug', 'classic-black-abaya')->first();
        $gown = Product::where('slug', 'elegant-evening-gown')->first();
        $necklace = Product::where('slug', '18k-gold-plated-necklace')->first();
        $tote = Product::where('slug', 'italian-leather-tote')->first();

        $userWithOrders = User::where('email', 'user_with_orders@store.com')->first();
        $customer = User::where('email', 'customer@store.com')->first();
        $userWishlist = User::where('email', 'user_wishlist@store.com')->first();
        $userNoAddress = User::where('email', 'user_no_address@store.com')->first();
        $userCancelled = User::where('email', 'user_cancelled_orders@store.com')->first();

        // Approved order for userWithOrders to link verified reviews
        $order = Order::where('user_id', $userWithOrders?->id)->where('status', 'delivered')->first();

        $reviews = [
            // --- Abaya Reviews (Product with many reviews) ---
            [
                'product_id' => $abaya?->id,
                'user_id'    => $userWithOrders?->id,
                'order_id'   => $order?->id,
                'rating'     => 5,
                'comment'    => 'Excellent material and perfect fit! Highly recommended. القماش رائع جداً ومريح في اللبس والمقاس مضبوط تماماً.',
                'status'     => 'approved',
            ],
            [
                'product_id' => $abaya?->id,
                'user_id'    => $customer?->id,
                'order_id'   => null,
                'rating'     => 4,
                'comment'    => 'Good abaya for daily use. Simple design but elegant. جميلة جداً وعملية للاستخدام اليومي.',
                'status'     => 'approved',
            ],
            [
                'product_id' => $abaya?->id,
                'user_id'    => $userWishlist?->id,
                'order_id'   => null,
                'rating'     => 5,
                'comment'    => 'I love this abaya! The color is solid deep black. خامة ممتازة ولون أسود فاحم وجميل.',
                'status'     => 'approved',
            ],
            [
                'product_id' => $abaya?->id,
                'user_id'    => $userNoAddress?->id,
                'order_id'   => null,
                'rating'     => 3,
                'comment'    => 'Wait time for shipping was a bit long, but the product is fine.',
                'status'     => 'pending', // Pending review
            ],
            [
                'product_id' => $abaya?->id,
                'user_id'    => $userCancelled?->id,
                'order_id'   => null,
                'rating'     => 1,
                'comment'    => 'Spam comment or abusive review text to test rejected state.',
                'status'     => 'rejected', // Rejected review
            ],

            // --- Evening Gown Reviews ---
            [
                'product_id' => $gown?->id,
                'user_id'    => $customer?->id,
                'order_id'   => null,
                'rating'     => 5,
                'comment'    => 'Fits like a glove! Elegant material and premium finish. الفستان روعة وخلاب في اللبس.',
                'status'     => 'approved',
            ],
            [
                'product_id' => $gown?->id,
                'user_id'    => $userWithOrders?->id,
                'order_id'   => null,
                'rating'     => 4,
                'comment'    => 'Very beautiful dress. Slightly long, but perfect otherwise.',
                'status'     => 'approved',
            ],

            // --- Necklace Reviews ---
            [
                'product_id' => $necklace?->id,
                'user_id'    => $customer?->id,
                'order_id'   => null,
                'rating'     => 5,
                'comment'    => 'Gleams beautifully in light. Plating holds up very well to water. اللمعة تجنن وثابتة ما يتغير لونها.',
                'status'     => 'approved',
            ],
            [
                'product_id' => $necklace?->id,
                'user_id'    => $userWishlist?->id,
                'order_id'   => null,
                'rating'     => 2,
                'comment'    => 'Pendant is smaller than expected, chain is very thin. السلسلة نحيفة جداً والقلادة أصغر من الصورة.',
                'status'     => 'approved',
            ],

            // --- Tote Bag Reviews ---
            [
                'product_id' => $tote?->id,
                'user_id'    => $userWithOrders?->id,
                'order_id'   => null,
                'rating'     => 5,
                'comment'    => 'Absolutely worth every penny. Smells like genuine high-quality leather. الجلد طبيعي وممتاز والمساحة كافية.',
                'status'     => 'approved',
            ],
        ];

        foreach ($reviews as $revData) {
            if (empty($revData['product_id']) || empty($revData['user_id'])) {
                continue;
            }

            Review::create($revData);
        }

        // Recalculate rating and reviews_count columns on products
        $products = Product::all();
        foreach ($products as $product) {
            $approvedReviews = $product->reviews()->where('status', 'approved')->get();
            $count = $approvedReviews->count();
            $average = $count > 0 ? round($approvedReviews->avg('rating'), 2) : 0.00;

            $product->update([
                'reviews_count' => $count,
                'rating'        => $average,
            ]);
        }
    }
}
