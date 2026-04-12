<?php

namespace App\Services;

use App\Models\Product;
use App\Models\WishlistEvent;
use Illuminate\Support\Facades\DB;

class WishlistAnalyticsService
{
    /**
     * Products ranked by current wishlist count (paginated).
     */
    public function getProductsByWishlistCount(int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        return Product::select('products.*')
            ->addSelect(DB::raw('COUNT(wishlist_items.id) as wishlist_count'))
            ->leftJoin('wishlist_items', 'wishlist_items.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->groupBy('products.id')
            ->orderByDesc('wishlist_count')
            ->with('images')
            ->paginate($perPage);
    }

    /**
     * Enhanced summary stats for the admin dashboard.
     * Fixes the distinct()->count() bug from the original implementation.
     */
    public function getSummary(): array
    {
        $totalEntries    = DB::table('wishlist_items')->count();
        $uniqueProducts  = DB::table('wishlist_items')->distinct()->count('product_id'); // Bug fixed
        $uniqueUsers     = DB::table('wishlist_items')->distinct()->count('user_id');    // Bug fixed

        $topProduct = Product::select('products.*')
            ->addSelect(DB::raw('COUNT(wishlist_items.id) as wishlist_count'))
            ->leftJoin('wishlist_items', 'wishlist_items.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->groupBy('products.id')
            ->orderByDesc('wishlist_count')
            ->with('images')
            ->first();

        // Week-over-week trend
        $thisWeekAdded    = WishlistEvent::where('action', 'added')
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        $lastWeekAdded    = WishlistEvent::where('action', 'added')
            ->whereBetween('created_at', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()])
            ->count();

        return [
            'total_wishlist_entries'     => $totalEntries,
            'unique_wishlisted_products' => $uniqueProducts,
            'users_with_wishlist'        => $uniqueUsers,
            'top_product'                => $topProduct ? [
                'id'             => $topProduct->id,
                'name'           => $topProduct->name,
                'wishlist_count' => (int) $topProduct->wishlist_count,
                'image'          => $topProduct->images->first()?->url,
            ] : null,
            'this_week_adds'  => $thisWeekAdded,
            'last_week_adds'  => $lastWeekAdded,
            'growth_rate'     => $lastWeekAdded > 0
                ? round((($thisWeekAdded - $lastWeekAdded) / $lastWeekAdded) * 100, 1)
                : null,
        ];
    }

    /**
     * Products trending by wishlist adds in the last N days.
     */
    public function getTrending(int $days = 7, int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        $since = now()->subDays($days);

        return Product::select('products.*')
            ->addSelect(DB::raw('COUNT(wishlist_events.id) as recent_adds'))
            ->join('wishlist_events', function ($join) use ($since) {
                $join->on('wishlist_events.product_id', '=', 'products.id')
                     ->where('wishlist_events.action', '=', 'added')
                     ->where('wishlist_events.created_at', '>=', $since);
            })
            ->whereNull('products.deleted_at')
            ->groupBy('products.id')
            ->orderByDesc('recent_adds')
            ->with('images')
            ->paginate($perPage);
    }

    /**
     * Wishlist-to-purchase conversion data.
     * Shows products that users wishlisted and then actually bought.
     */
    public function getConversions(int $perPage = 20): \Illuminate\Pagination\LengthAwarePaginator
    {
        // Products that appear in both wishlist_items and delivered order_items
        return Product::select('products.*')
            ->addSelect(DB::raw('COUNT(DISTINCT wishlist_items.user_id) as total_wishlisted'))
            ->addSelect(DB::raw('COUNT(DISTINCT converted_orders.user_id) as total_converted'))
            ->leftJoin('wishlist_items', 'wishlist_items.product_id', '=', 'products.id')
            ->leftJoin(
                DB::raw("(
                    SELECT order_items.product_id, orders.user_id
                    FROM order_items
                    JOIN orders ON orders.id = order_items.order_id
                    WHERE orders.status = 'delivered'
                    AND orders.deleted_at IS NULL
                ) as converted_orders"),
                'converted_orders.product_id', '=', 'products.id'
            )
            ->whereNull('products.deleted_at')
            ->groupBy('products.id')
            ->having('total_wishlisted', '>', 0)
            ->orderByDesc('total_converted')
            ->with('images')
            ->paginate($perPage);
    }
}
