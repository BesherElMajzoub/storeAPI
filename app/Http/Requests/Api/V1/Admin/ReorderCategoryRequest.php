<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Services\CategoryService;
use Illuminate\Validation\Rule;

class ReorderCategoryRequest extends BaseAdminRequest
{
    public function rules(): array
    {
        return [
            'categories' => ['required', 'array', 'min:1'],
            'categories.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'categories.*.parent_id' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->whereNull('deleted_at'),
            ],
            'categories.*.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $categories = $this->input('categories', []);
            if (!is_array($categories) || $categories === []) {
                return;
            }

            foreach ($categories as $index => $item) {
                $id = (int) ($item['id'] ?? 0);
                $parentId = $item['parent_id'] ?? null;
                if ($parentId !== null && $id > 0 && (int) $parentId === $id) {
                    $validator->errors()->add("categories.$index.parent_id", 'Category cannot be its own parent.');
                }
            }

            $service = app(CategoryService::class);
            $circularIds = $service->findCircularInUpdates($categories);
            if ($circularIds !== []) {
                $validator->errors()->add(
                    'categories',
                    'Circular parent assignment detected for ids: ' . implode(', ', $circularIds) . '.'
                );
            }
        });
    }
}
