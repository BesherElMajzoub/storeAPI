<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class WishlistAnalyticsController extends Controller
{
    /**
     * GET /admin/wishlist-analytics
     * 
     * Admin > Wishlist Analytics
     * Returns all products sorted by their wishlist count (highest first).
     */
    #[OA\Get(
        path: "/api/v1/admin/wishlist-analytics",
        summary: "Admin Wishlist Analytics",
        description: "Returns all products sorted by their wishlist count",
        security: [["bearerAuth" => []]],
        tags: ["Admin Wishlist"]
    )]
    #[OA\Parameter(name: "per_page", in: "query", schema: new OA\Schema(type: "integer", default: 20))]
    #[OA\Response(
        response: 200,
        description: "Analytics fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $products = Product::select('products.*')
            ->addSelect(DB::raw('COUNT(wishlists.id) as wishlist_count'))
            ->leftJoin('wishlists', 'wishlists.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')  // respect soft deletes
            ->groupBy('products.id')
            ->orderByDesc('wishlist_count')
            ->with('images')
            ->paginate($perPage);

        $formatted = $products->through(fn ($product) => [
            'id'             => $product->id,
            'name'           => $product->name,
            'slug'           => $product->slug,
            'price'          => (float) $product->price,
            'final_price'    => (float) $product->final_price,
            'image'          => $product->images->first()?->url,
            'wishlist_count' => (int) $product->wishlist_count,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Wishlist analytics fetched.',
            'data'    => $formatted,
            'errors'  => null,
        ]);
    }

    /**
     * GET /admin/wishlist-analytics/summary
     * 
     * Quick summary stats for the dashboard.
     */
    #[OA\Get(
        path: "/api/v1/admin/wishlist-analytics/summary",
        summary: "Admin Wishlist Summary",
        description: "Quick summary stats of wishlists for the dashboard",
        security: [["bearerAuth" => []]],
        tags: ["Admin Wishlist"]
    )]
    #[OA\Response(
        response: 200,
        description: "Summary fetched",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", type: "object")
            ]
        )
    )]
    public function summary(): JsonResponse
    {
        $totalWishlists = DB::table('wishlists')->count();
        $uniqueProducts = DB::table('wishlists')->distinct('product_id')->count();
        $uniqueUsers    = DB::table('wishlists')->distinct('user_id')->count();

        $topProduct = Product::select('products.*')
            ->addSelect(DB::raw('COUNT(wishlists.id) as wishlist_count'))
            ->leftJoin('wishlists', 'wishlists.product_id', '=', 'products.id')
            ->whereNull('products.deleted_at')
            ->groupBy('products.id')
            ->orderByDesc('wishlist_count')
            ->with('images')
            ->first();

        return response()->json([
            'success' => true,
            'message' => 'Wishlist summary fetched.',
            'data'    => [
                'total_wishlist_entries' => $totalWishlists,
                'unique_wishlisted_products' => $uniqueProducts,
                'users_with_wishlist' => $uniqueUsers,
                'top_product' => $topProduct ? [
                    'id'             => $topProduct->id,
                    'name'           => $topProduct->name,
                    'wishlist_count' => (int) $topProduct->wishlist_count,
                    'image'          => $topProduct->images->first()?->url,
                ] : null,
            ],
            'errors'  => null,
        ]);
    }
}
