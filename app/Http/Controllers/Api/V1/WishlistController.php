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
    /**
     * GET /wishlist
     * Return all products in the authenticated user's wishlist.
     */
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
                new OA\Property(property: "message", type: "string", example: "Wishlist fetched."),
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
        $products = $request->user()
            ->wishlistProducts()
            ->with(['images', 'category'])
            ->latest('wishlists.created_at')
            ->get();

        return $this->success(
            WishlistItemResource::collection($products),
            'Wishlist fetched.'
        );
    }

    /**
     * POST /wishlist/{product}
     * Toggle: add the product if not in wishlist, remove if it is.
     * This is a toggle approach — ideal for a button that flips state.
     */
    #[OA\Post(
        path: "/api/v1/wishlist/{product}",
        summary: "Toggle Wishlist Product",
        description: "Toggle a product in the user's wishlist (add if not present, remove if present)",
        security: [["bearerAuth" => []]],
        tags: ["Wishlist"]
    )]
    #[OA\Parameter(name: "product", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Product removed from wishlist",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Product removed from wishlist."),
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [new OA\Property(property: "in_wishlist", type: "boolean", example: false)]
                )
            ]
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Product added to wishlist",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Product added to wishlist."),
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [new OA\Property(property: "in_wishlist", type: "boolean", example: true)]
                )
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    public function toggle(Request $request, int $productId): JsonResponse
    {
        $product = Product::published()->findOrFail($productId);
        $user    = $request->user();

        $exists = $user->wishlistProducts()->where('product_id', $product->id)->exists();

        if ($exists) {
            $user->wishlistProducts()->detach($product->id);
            return $this->success(
                ['in_wishlist' => false],
                'Product removed from wishlist.'
            );
        }

        $user->wishlistProducts()->attach($product->id);
        return $this->success(
            ['in_wishlist' => true],
            'Product added to wishlist.',
            201
        );
    }

    /**
     * DELETE /wishlist/{product}
     * Explicitly remove a product from the wishlist.
     */
    #[OA\Delete(
        path: "/api/v1/wishlist/{product}",
        summary: "Remove Wishlist Product",
        description: "Explicitly remove a product from the user's wishlist",
        security: [["bearerAuth" => []]],
        tags: ["Wishlist"]
    )]
    #[OA\Parameter(name: "product", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Product removed from wishlist",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "success", type: "boolean", example: true),
                new OA\Property(property: "message", type: "string", example: "Product removed from wishlist."),
                new OA\Property(
                    property: "data",
                    type: "object",
                    properties: [new OA\Property(property: "in_wishlist", type: "boolean", example: false)]
                )
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    #[OA\Response(response: 401, ref: "#/components/responses/ErrorResponse")]
    public function destroy(Request $request, int $productId): JsonResponse
    {
        $user = $request->user();

        // Check the product exists in wishlist
        $inWishlist = $user->wishlistProducts()->where('product_id', $productId)->exists();

        if (!$inWishlist) {
            return $this->success(null, 'Product not found in wishlist.', 404);
        }

        $user->wishlistProducts()->detach($productId);

        return $this->success(['in_wishlist' => false], 'Product removed from wishlist.');
    }

    private function success(mixed $data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $data,
            'errors'  => null,
        ], $status);
    }
}
