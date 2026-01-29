<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Category;
use App\Services\CategoryService;
use Illuminate\Validation\Rule;

class UpdateCategoryRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255'],
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (!$this->has('parent_id')) {
                return;
            }

            $parentId = $this->input('parent_id');
            if ($parentId === null) {
                return;
            }

            $routeCategory = $this->route('category');
            $categoryId = $routeCategory instanceof Category
                ? $routeCategory->id
                : (int) $this->route('id');

            if ($categoryId <= 0) {
                return;
            }

            $service = app(CategoryService::class);
            if ($service->hasCircularParent($categoryId, (int) $parentId)) {
                $validator->errors()->add('parent_id', 'Circular parent assignment detected.');
            }
        });
    }
}
