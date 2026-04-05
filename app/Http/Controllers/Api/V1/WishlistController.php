<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WishlistItemResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class WishlistController extends Controller
{
    #[OA\Get(
        path: "/api/v1/wishlist",
        summary: "Get Wishlist",
        description: "Return all products in the authenticated user's wishlist",
        security: [["bearerAuth" => []]],
        tags: ["Wishlist"]
    )]
    #[OA\Response(
        response: 200,
        description: "Wishlist fetched successfully",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(
                    property: "data",
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/Product")
                )
            ]
        )
    )]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()
            ->wishlistItems()
            ->with(['product.images', 'product.category'])
            ->whereHas('product') // Only return products that still exist
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data' => WishlistItemResource::collection($items),
        ]);
    }

    #[OA\Post(
        path: "/api/v1/wishlist",
        summary: "Add Product to Wishlist",
        description: "Add a product to the user's wishlist",
        security: [["bearerAuth" => []]],
        tags: ["Wishlist"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["product_id"],
            properties: [
                new OA\Property(property: "product_id", type: "integer", example: 1)
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Product added to wishlist",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Product added to wishlist.")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 422, ref: "#/components/responses/ErrorResponse")]
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => 'required|exists:products,id'
        ]);

        $user = $request->user();
        $productId = $request->product_id;

        $product = Product::published()->find($productId);
        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found or not available.'
            ], 404);
        }

        $exists = $user->wishlistItems()->where('product_id', $productId)->exists();

        if ($exists) {
            return response()->json([
                'success' => true,
                'message' => 'Product already in wishlist.'
            ]);
        }

        $user->wishlistItems()->create([
            'product_id' => $productId
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product added to wishlist.'
        ], 201);
    }

    #[OA\Delete(
        path: "/api/v1/wishlist/{productId}",
        summary: "Remove Wishlist Product",
        description: "Explicitly remove a product from the user's wishlist",
        security: [["bearerAuth" => []]],
        tags: ["Wishlist"]
    )]
    #[OA\Parameter(name: "productId", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Product removed from wishlist",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Product removed from wishlist.")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function destroy(Request $request, $productId): JsonResponse
    {
        $user = $request->user();

        $deleted = $user->wishlistItems()->where('product_id', $productId)->delete();

        if (!$deleted) {
            return response()->json([
                'success' => false,
                'message' => 'Product not found in wishlist.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Product removed from wishlist.'
        ]);
    }

    #[OA\Get(
        path: "/api/v1/wishlist/check/{productId}",
        summary: "Check if product is in wishlist",
        description: "Returns a boolean indicating if the product exists in wishlist",
        security: [["bearerAuth" => []]],
        tags: ["Wishlist"]
    )]
    #[OA\Parameter(name: "productId", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Check status fetched",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "data", type: "object", properties: [
                    new OA\Property(property: "in_wishlist", type: "boolean", example: true)
                ])
            ]
        )
    )]
    public function check(Request $request, $productId): JsonResponse
    {
        $exists = $request->user()->wishlistItems()->where('product_id', $productId)->exists();

        return response()->json([
            'success' => true,
            'data' => ['in_wishlist' => $exists]
        ]);
    }

    #[OA\Get(
        path: "/api/v1/wishlist/count",
        summary: "Get Wishlist Count",
        description: "Returns the total count of items in the wishlist",
        security: [["bearerAuth" => []]],
        tags: ["Wishlist"]
    )]
    #[OA\Response(
        response: 200,
        description: "Count fetched",
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "data", type: "object", properties: [
                    new OA\Property(property: "count", type: "integer", example: 5)
                ])
            ]
        )
    )]
    public function count(Request $request): JsonResponse
    {
        $count = $request->user()->wishlistItems()->count();

        return response()->json([
            'success' => true,
            'data' => ['count' => $count]
        ]);
    }
}
