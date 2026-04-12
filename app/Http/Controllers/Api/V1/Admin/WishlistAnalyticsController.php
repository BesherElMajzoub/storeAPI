<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\WishlistAnalyticsService;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class WishlistAnalyticsController extends Controller
{
    use ApiResponseTrait;

    public function __construct(private readonly WishlistAnalyticsService $analyticsService) {}

    #[OA\Get(
        path: '/api/v1/admin/wishlist-analytics',
        summary: 'Admin Wishlist Analytics',
        description: 'Returns all products sorted by their current wishlist count',
        security: [['bearerAuth' => []]],
        tags: ['Admin Wishlist']
    )]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: 'Analytics fetched')]
    public function index(Request $request): JsonResponse
    {
        $perPage  = min(max((int) $request->get('per_page', 20), 1), 100);
        $products = $this->analyticsService->getProductsByWishlistCount($perPage);

        $formatted = $products->through(fn($product) => [
            'id'             => $product->id,
            'name'           => $product->name,
            'slug'           => $product->slug,
            'price'          => (float) $product->price,
            'final_price'    => (float) $product->final_price,
            'image'          => $product->images->first()?->url,
            'wishlist_count' => (int) $product->wishlist_count,
        ]);

        return $this->success($formatted, 'Wishlist analytics fetched.');
    }

    #[OA\Get(
        path: '/api/v1/admin/wishlist-analytics/summary',
        summary: 'Admin Wishlist Summary',
        description: 'Enhanced summary stats with week-over-week trend data',
        security: [['bearerAuth' => []]],
        tags: ['Admin Wishlist']
    )]
    #[OA\Response(response: 200, description: 'Summary fetched')]
    public function summary(): JsonResponse
    {
        return $this->success(
            $this->analyticsService->getSummary(),
            'Wishlist summary fetched.'
        );
    }

    #[OA\Get(
        path: '/api/v1/admin/wishlist-analytics/trending',
        summary: 'Admin Wishlist Trending',
        description: 'Products trending by wishlist adds in the last N days',
        security: [['bearerAuth' => []]],
        tags: ['Admin Wishlist']
    )]
    #[OA\Parameter(name: 'days', in: 'query', schema: new OA\Schema(type: 'integer', default: 7))]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: 'Trending products fetched')]
    public function trending(Request $request): JsonResponse
    {
        $days    = min(max((int) $request->get('days', 7), 1), 90);
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $products = $this->analyticsService->getTrending($days, $perPage);

        $formatted = $products->through(fn($product) => [
            'id'          => $product->id,
            'name'        => $product->name,
            'slug'        => $product->slug,
            'price'       => (float) $product->price,
            'final_price' => (float) $product->final_price,
            'image'       => $product->images->first()?->url,
            'recent_adds' => (int) $product->recent_adds,
        ]);

        return $this->success($formatted, "Trending products in the last {$days} days.");
    }

    #[OA\Get(
        path: '/api/v1/admin/wishlist-analytics/conversions',
        summary: 'Admin Wishlist Conversions',
        description: 'Products with wishlist-to-purchase conversion data',
        security: [['bearerAuth' => []]],
        tags: ['Admin Wishlist']
    )]
    #[OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', default: 20))]
    #[OA\Response(response: 200, description: 'Conversion data fetched')]
    public function conversions(Request $request): JsonResponse
    {
        $perPage  = min(max((int) $request->get('per_page', 20), 1), 100);
        $products = $this->analyticsService->getConversions($perPage);

        $formatted = $products->through(function ($product) {
            $wishlisted = (int) $product->total_wishlisted;
            $converted  = (int) $product->total_converted;

            return [
                'id'               => $product->id,
                'name'             => $product->name,
                'slug'             => $product->slug,
                'price'            => (float) $product->price,
                'image'            => $product->images->first()?->url,
                'total_wishlisted' => $wishlisted,
                'total_converted'  => $converted,
                'conversion_rate'  => $wishlisted > 0
                    ? round(($converted / $wishlisted) * 100, 1)
                    : 0,
            ];
        });

        return $this->success($formatted, 'Wishlist conversion data fetched.');
    }
}
