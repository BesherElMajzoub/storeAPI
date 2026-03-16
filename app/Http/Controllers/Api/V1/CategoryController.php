<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    #[OA\Get(
        path: "/api/v1/categories",
        summary: "List Categories",
        description: "Get a list of active top-level categories, including their children",
        tags: ["Categories"]
    )]
    #[OA\Response(
        response: 200,
        description: "Successful response",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(
                    property: "data",
                    type: "array",
                    items: new OA\Items(ref: "#/components/schemas/Category")
                )
            ]
        )
    )]
    public function index()
    {
        $categories = Category::where('is_active', true)
            ->whereNull('parent_id')
            ->with('children')
            ->orderBy('sort_order')
            ->get();

        return CategoryResource::collection($categories);
    }

    #[OA\Get(
        path: "/api/v1/categories/{slug}",
        summary: "Get Category",
        description: "Get a single category by slug",
        tags: ["Categories"]
    )]
    #[OA\Parameter(
        name: "slug",
        in: "path",
        required: true,
        schema: new OA\Schema(type: "string"),
        description: "The slug of the category"
    )]
    #[OA\Response(
        response: 200,
        description: "Successful response",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/Category")
            ]
        )
    )]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function show($slug)
    {
        $category = Category::where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        return new CategoryResource($category);
    }
}
