<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReorderCategoryRequest;
use App\Http\Requests\Api\V1\Admin\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCategoryRequest;
use App\Http\Resources\CategoryDetailResource;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class CategoryController extends Controller
{
    #[OA\Get(
        path: "/api/v1/admin/categories",
        summary: "Admin List Categories",
        description: "List all categories for admin",
        security: [["bearerAuth" => []]],
        tags: ["Admin Categories"]
    )]
    #[OA\Parameter(name: "depth", in: "query", schema: new OA\Schema(type: "integer", default: 2))]
    #[OA\Response(
        response: 200 ,
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
    public function index(Request $request): JsonResponse
    {
        $depth = (int) $request->query('depth', 2);
        $depth = max(0, min($depth, 5));

        $query = Category::query()
            ->whereNull('parent_id')
            ->orderBy('sort_order');

        if ($depth > 0) {
            $query->with($this->buildChildrenDepth($depth));
        }

        $categories = $query->with('media')->get();

        return $this->success(CategoryDetailResource::collection($categories), 'Categories fetched.');
    }

    #[OA\Post(
        path: "/api/v1/admin/categories",
        summary: "Admin Create Category",
        description: "Create a new category. Send as **multipart/form-data** to support image file uploads.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Categories"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: "multipart/form-data",
            schema: new OA\Schema(
                required: ["name"],
                properties: [
                    new OA\Property(property: "name", type: "string", example: "Electronics"),
                    new OA\Property(property: "slug", type: "string", example: "electronics"),
                    new OA\Property(property: "parent_id", type: "integer", nullable: true, example: null),
                    new OA\Property(property: "is_active", type: "boolean", example: true),
                    new OA\Property(property: "description", type: "string", nullable: true),
                    new OA\Property(property: "image", type: "string", format: "binary", nullable: true, description: "Category image file (max 5MB)")
                ]
            )
        )
    )]
    #[OA\Response(
        response: 201,
        description: "Category created",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/Category")
            ]
        )
    )]
    #[OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse")]
    public function store(StoreCategoryRequest $request, CategoryService $service): JsonResponse
    {
        $data = $request->validated();
        $slugSource = $data['slug'] ?? $data['name'];
        $data['slug'] = $service->generateUniqueSlug($slugSource);

        // Remove 'image' from fillable data — Spatie handles it separately
        unset($data['image']);

        $category = Category::create($data);

        if ($request->hasFile('image')) {
            $category->addMediaFromRequest('image')
                ->toMediaCollection('category_image');
        }

        return $this->success(new CategoryDetailResource($category->load('media')), 'Category created.', 201);
    }

    #[OA\Post(
        path: "/api/v1/admin/categories/{category}",
        summary: "Admin Update Category",
        description: "Update an existing category. Send as **multipart/form-data** and include `_method=PATCH` field to support file uploads.",
        security: [["bearerAuth" => []]],
        tags: ["Admin Categories"]
    )]
    #[OA\Parameter(name: "category", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\RequestBody(
        required: true,
        content: new OA\MediaType(
            mediaType: "multipart/form-data",
            schema: new OA\Schema(
                properties: [
                    new OA\Property(property: "_method", type: "string", enum: ["PATCH"], example: "PATCH", description: "Method override required for multipart PATCH"),
                    new OA\Property(property: "name", type: "string"),
                    new OA\Property(property: "slug", type: "string"),
                    new OA\Property(property: "parent_id", type: "integer", nullable: true),
                    new OA\Property(property: "is_active", type: "boolean"),
                    new OA\Property(property: "description", type: "string", nullable: true),
                    new OA\Property(property: "image", type: "string", format: "binary", nullable: true, description: "Category image file (max 5MB)")
                ]
            )
        )
    )]
    #[OA\Response(
        response: 200,
        description: "Category updated",
        content: new OA\JsonContent(
            type: "object",
            properties: [
                new OA\Property(property: "data", ref: "#/components/schemas/Category")
            ]
        )
    )]
    #[OA\Response(response: 422, ref: "#/components/responses/ValidationErrorResponse")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function update(UpdateCategoryRequest $request, int $id, CategoryService $service): JsonResponse
    {
        $category = Category::findOrFail($id);
        $data = $request->validated();

        if (array_key_exists('slug', $data)) {
            $data['slug'] = $service->generateUniqueSlug($data['slug'], $category->id);
        }

        // Remove 'image' from fillable data — Spatie handles it separately
        unset($data['image']);

        $category->update($data);

        if ($request->hasFile('image')) {
            // singleFile() collection auto-clears the old image
            $category->addMediaFromRequest('image')
                ->toMediaCollection('category_image');
        }

        return $this->success(new CategoryDetailResource($category->refresh()->load('media')), 'Category updated.');
    }

    #[OA\Delete(
        path: "/api/v1/admin/categories/{category}",
        summary: "Admin Delete Category",
        description: "Delete a category",
        security: [["bearerAuth" => []]],
        tags: ["Admin Categories"]
    )]
    #[OA\Parameter(name: "category", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
    #[OA\Response(response: 200, description: "Category deleted")]
    #[OA\Response(response: 404, ref: "#/components/responses/ErrorResponse")]
    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return $this->success(null, 'Category deleted.');
    }

    #[OA\Post(
        path: "/api/v1/admin/categories/reorder",
        summary: "Admin Reorder Categories",
        description: "Reorder categories",
        security: [["bearerAuth" => []]],
        tags: ["Admin Categories"]
    )]
    #[OA\RequestBody(
        required: true,
        content: new OA\JsonContent(
            required: ["categories"],
            properties: [
                new OA\Property(
                    property: "categories",
                    type: "array",
                    items: new OA\Items(
                        type: "object",
                        properties: [
                            new OA\Property(property: "id", type: "integer"),
                            new OA\Property(property: "parent_id", type: "integer", nullable: true),
                            new OA\Property(property: "sort_order", type: "integer")
                        ]
                    )
                )
            ]
        )
    )]
    #[OA\Response(response: 200, description: "Categories reordered")]
    public function reorder(ReorderCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $updates = $data['categories'];
        $rows = [];
        $now = now();

        foreach ($updates as $item) {
            $rows[] = [
                'id' => $item['id'],
                'parent_id' => $item['parent_id'] ?? null,
                'sort_order' => $item['sort_order'],
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($rows) {
            Category::query()->upsert(
                $rows,
                ['id'],
                ['parent_id', 'sort_order', 'updated_at']
            );
        });

        $depth = (int) $request->query('depth', 2);
        $depth = max(0, min($depth, 5));

        $query = Category::query()
            ->whereNull('parent_id')
            ->orderBy('sort_order');

        if ($depth > 0) {
            $query->with($this->buildChildrenDepth($depth));
        }

        $categories = $query->get();

        return $this->success($categories, 'Categories reordered.');
    }

    #[OA\Get(
        path: "/api/v1/admin/categories/{category}",
        summary: "Admin Show Category",
        description: "Get a specific category for admin",
        security: [["bearerAuth" => []]],
        tags: ["Admin Categories"]
    )]
    #[OA\Parameter(name: "category", in: "path", required: true, schema: new OA\Schema(type: "integer"))]
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
    public function show(int $id): JsonResponse
    {
        $category = Category::with('media')->findOrFail($id);
        return $this->success(new CategoryDetailResource($category), 'Category fetched.');
    }

    private function success($data, string $message, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    private function buildChildrenDepth(int $depth): array
    {
        $relations = [];
        $relation = 'children';

        for ($i = 1; $i <= $depth; $i++) {
            $relations[] = $relation;
            $relation .= '.children';
        }

        return $relations;
    }
}
