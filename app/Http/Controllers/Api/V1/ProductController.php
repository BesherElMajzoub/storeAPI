<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Http\Resources\ReviewResource;
use App\Models\Product;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ProductController extends Controller
{
    #[OA\Get(
        path: "/api/v1/products",
        summary: "List Products",
        description: "Get a paginated list of published products with optional filters",
        tags: ["Products"]
    )]
    #[OA\Parameter(name: "search", in: "query", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "category", in: "query", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "price_min", in: "query", schema: new OA\Schema(type: "number"))]
    #[OA\Parameter(name: "price_max", in: "query", schema: new OA\Schema(type: "number"))]
    #[OA\Parameter(name: "rating", in: "query", schema: new OA\Schema(type: "number"))]
    #[OA\Parameter(name: "in_stock", in: "query", schema: new OA\Schema(type: "boolean"))]
    #[OA\Parameter(name: "sort", in: "query", schema: new OA\Schema(type: "string"))]
    #[OA\Parameter(name: "per_page", in: "query", schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Successful response",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(
                    property: "data",
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/Product")
                ),
                new OA\Property(property: "meta", ref: "#/components/schemas/PaginationMeta")
            ]
        )
    )]
    public function index(Request $request)
    {
        $filters = $request->only([
            'search',
            'category',
            'price_min',
            'price_max',
            'rating',
            'in_stock',
        ]);
        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $products = Product::query()
            ->published()
            ->with(['category', 'images', 'variants'])
            ->filter($filters)
            ->sort($request->get('sort', 'newest'))
            ->paginate($perPage);

        return ProductResource::collection($products);
    }

    #[OA\Get(
        path: "/api/v1/products/{slug}",
        summary: "Get Product details",
        description: "Get full details of a published product by its slug",
        tags: ["Products"]
    )]
    #[OA\Parameter(name: "slug", in: "path", required: true, schema: new OA\Schema(type: "string"))]
    #[OA\Response(
        response: 200,
        description: "Successful response",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/Product")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function show($slug)
    {
        $product = Product::where('slug', $slug)
            ->published()
            ->with([
                'category',
                'images',
                'variants',
                'reviews' => function ($query) {
                    $query->where('is_approved', true)->with('user');
                },
            ])
            ->firstOrFail();

        return new ProductResource($product);
    }

    #[OA\Get(
        path: "/api/v1/products/{id}/reviews",
        summary: "Get Product Reviews",
        description: "Get a paginated list of approved reviews for a specific product",
        tags: ["Products", "Reviews"]
    )]
    #[OA\Parameter(name: "id", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(
        response: 200,
        description: "Successful response",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(
                    property: "data",
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/Review")
                ),
                new OA\Property(property: "meta", ref: "#/components/schemas/PaginationMeta")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function reviews($id)
    {
        $product = Product::published()->findOrFail($id);

        $reviews = $product->reviews()
            ->where('is_approved', true)
            ->with('user')
            ->latest()
            ->paginate(10);

        return ReviewResource::collection($reviews);
    }
}
