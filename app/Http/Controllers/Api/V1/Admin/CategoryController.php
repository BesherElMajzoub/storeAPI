<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReorderCategoryRequest;
use App\Http\Requests\Api\V1\Admin\StoreCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCategoryRequest;
use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CategoryController extends Controller
{
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

        $categories = $query->get();

        return $this->success($categories, 'Categories fetched.');
    }

    public function store(StoreCategoryRequest $request, CategoryService $service): JsonResponse
    {
        $data = $request->validated();
        $slugSource = $data['slug'] ?? $data['name'];
        $data['slug'] = $service->generateUniqueSlug($slugSource);

        $category = Category::create($data);

        return $this->success($category, 'Category created.', 201);
    }

    public function update(UpdateCategoryRequest $request, int $id, CategoryService $service): JsonResponse
    {
        $category = Category::findOrFail($id);
        $data = $request->validated();

        if (array_key_exists('slug', $data)) {
            $data['slug'] = $service->generateUniqueSlug($data['slug'], $category->id);
        }

        $category->update($data);

        return $this->success($category->refresh(), 'Category updated.');
    }

    public function destroy(int $id): JsonResponse
    {
        $category = Category::findOrFail($id);
        $category->delete();

        return $this->success(null, 'Category deleted.');
    }

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
