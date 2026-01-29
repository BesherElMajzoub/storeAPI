<?php

namespace App\Services;

use App\Models\Category;
use Illuminate\Support\Str;

class CategoryService
{
    public function generateUniqueSlug(string $value, ?int $ignoreId = null): string
    {
        $base = Str::slug($value);
        if ($base === '') {
            $base = 'category';
        }

        $slug = $base;
        $suffix = 2;

        while ($this->slugExists($slug, $ignoreId)) {
            $slug = $base . '-' . $suffix;
            $suffix++;
        }

        return $slug;
    }

    public function hasCircularParent(int $categoryId, ?int $parentId, ?array $parentMap = null): bool
    {
        if ($parentId === null) {
            return false;
        }

        if ($parentId === $categoryId) {
            return true;
        }

        $parentMap = $parentMap ?? Category::query()->pluck('parent_id', 'id')->all();
        $parentMap[$categoryId] = $parentId;

        return $this->detectCycle($categoryId, $parentMap);
    }

    public function findCircularInUpdates(array $updates): array
    {
        $parentMap = Category::query()->pluck('parent_id', 'id')->all();

        foreach ($updates as $update) {
            $id = (int) ($update['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $parentMap[$id] = $update['parent_id'] ?? null;
        }

        $circularIds = [];
        foreach ($updates as $update) {
            $id = (int) ($update['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($this->detectCycle($id, $parentMap)) {
                $circularIds[] = $id;
            }
        }

        return array_values(array_unique($circularIds));
    }

    private function slugExists(string $slug, ?int $ignoreId = null): bool
    {
        return Category::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, function ($query) use ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            })
            ->exists();
    }

    private function detectCycle(int $startId, array $parentMap): bool
    {
        $visited = [];
        $current = $startId;
        $maxSteps = count($parentMap) + 1;
        $steps = 0;

        while (true) {
            $parent = $parentMap[$current] ?? null;
            if ($parent === null) {
                return false;
            }

            if ($parent === $startId || isset($visited[$parent])) {
                return true;
            }

            $visited[$parent] = true;
            $current = $parent;
            $steps++;

            if ($steps > $maxSteps) {
                return true;
            }
        }
    }
}
