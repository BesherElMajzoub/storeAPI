<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\Concerns\CreatesDemoMedia;

class DemoCatalogSeeder extends DemoSeeder
{
    use CreatesDemoMedia;

    public const PRODUCT_COUNT = 96;

    public function run(): void
    {
        $this->guardAgainstProduction();

        $categories = $this->seedCategories();
        $this->seedProducts($categories);

        $this->command?->info('Demo catalogue: 36 categories, 96 products, 200+ variants, and varied galleries.');
    }

    private function seedCategories(): array
    {
        $tree = [
            'Women' => ['Dresses', 'Abayas', 'Tops'],
            'Accessories' => ['Jewelry', 'Scarves', 'Belts'],
            'Bags' => ['Handbags', 'Clutches', 'Totes'],
            'Shoes' => ['Heels', 'Flats', 'Sandals'],
            'Beauty' => ['Perfume', 'Skincare', 'Makeup'],
            'Home' => ['Decor', 'Candles', 'Textiles'],
            'Gifts' => ['Gift Sets', 'Under 50', 'Premium Gifts'],
            'Seasonal' => ['Summer Edit', 'Winter Edit', 'Occasion Wear'],
            'Frontend States' => ['Empty Category', 'Inactive Category', 'Very Long Category Name Used To Test Navigation Wrapping'],
        ];
        $created = [];

        foreach ($tree as $rootIndex => $children) {
            $rootOrder = array_search($rootIndex, array_keys($tree), true) + 1;
            $rootSlug = 'demo-'.str($rootIndex)->slug();
            $root = Category::updateOrCreate(
                ['slug' => $rootSlug],
                [
                    'name' => $rootIndex,
                    'description' => 'Demo root category for navigation, filtering, SEO, and responsive layout testing.',
                    'parent_id' => null,
                    'is_active' => true,
                    'sort_order' => $rootOrder,
                    'meta_title' => $rootIndex.' | Otantik Queen Demo',
                    'meta_description' => 'Browse the '.$rootIndex.' frontend demonstration collection.',
                ]
            );
            $this->attachDemoImages($root, 'category_image', 1, $rootIndex);
            $created[] = $root;

            foreach ($children as $childIndex => $childName) {
                $childSlug = 'demo-'.str($rootIndex.'-'.$childName)->slug();
                $inactive = $childName === 'Inactive Category';
                $child = Category::updateOrCreate(
                    ['slug' => $childSlug],
                    [
                        'name' => $childName,
                        'description' => $inactive
                            ? 'Intentionally inactive; it must not appear in the public category tree.'
                            : 'A realistic child category with enough content to exercise filters and category pages.',
                        'parent_id' => $root->id,
                        'is_active' => ! $inactive,
                        'sort_order' => $childIndex + 1,
                        'meta_title' => $childName.' Demo Collection',
                        'meta_description' => 'Frontend demonstration content for '.$childName.'.',
                    ]
                );
                $this->attachDemoImages($child, 'category_image', 1, $childName);
                $created[] = $child;
            }
        }

        return $created;
    }

    private function seedProducts(array $categories): void
    {
        $productCategories = collect($categories)
            ->filter(fn (Category $category) => $category->parent_id
                && $category->is_active
                && ! str_contains($category->slug, 'empty-category'))
            ->values();
        $adjectives = ['Silk', 'Classic', 'Modern', 'Royal', 'Minimal', 'Embroidered', 'Premium', 'Everyday', 'Signature', 'Satin', 'Velvet', 'Linen'];
        $types = ['Dress', 'Abaya', 'Scarf', 'Handbag', 'Clutch', 'Sandal', 'Perfume', 'Candle', 'Gift Box', 'Necklace', 'Blouse', 'Home Set'];

        for ($index = 1; $index <= self::PRODUCT_COUNT; $index++) {
            $category = $productCategories[($index - 1) % $productCategories->count()];
            $status = $index % 17 === 0 ? 'archived' : ($index % 13 === 0 ? 'draft' : 'published');
            $variantCount = $index % 4 === 0 ? 0 : ($index % 10 === 0 ? 5 : 3);
            $variantStocks = $this->variantStocks($index, $variantCount);
            $stock = $variantCount > 0 ? array_sum($variantStocks) : $this->simpleStock($index);
            $price = $this->price($index);
            $name = $adjectives[($index - 1) % count($adjectives)].' '.$types[($index - 1) % count($types)].' '.sprintf('%02d', $index);

            if ($index % 9 === 0) {
                $name .= ' / قطعة أنيقة للاختبار باللغة العربية';
            }
            if ($index === 2) {
                $name = 'Extra Long Product Name Designed To Reveal Card Truncation, Responsive Grid Overflow, Search Result Wrapping, And Admin Table Layout Problems';
            }

            $measurements = $this->measurements($index, $status);
            $product = Product::updateOrCreate(
                ['sku' => sprintf('DEMO-P-%03d', $index)],
                [
                    'name' => $name,
                    'slug' => sprintf('demo-product-%03d-%s', $index, str($types[($index - 1) % count($types)])->slug()),
                    'description' => $this->description($index),
                    'price' => $price,
                    'discount_price' => $index % 3 === 0 ? round($price * 0.78, 2) : null,
                    'stock_qty' => $stock,
                    ...$measurements,
                    'status' => $status,
                    'category_id' => $status === 'draft' && $index % 2 === 0 ? null : $category->id,
                    'options' => $variantCount > 0
                        ? ['Color' => ['Black', 'Ivory', 'Rose'], 'Size' => ['S', 'M', 'L', 'XL']]
                        : null,
                    'in_stock' => $stock > 0,
                    'is_featured' => $status === 'published' && ($index <= 8 || $index % 19 === 0),
                    'meta_title' => mb_substr($name, 0, 250),
                    'meta_description' => 'SEO description for '.$name.' with a realistic price and stock state.',
                    'rating' => 0,
                    'reviews_count' => 0,
                ]
            );

            $this->seedVariants($product, $index, $variantStocks, $price);
            $this->seedProductGallery($product, $index);
        }
    }

    private function seedVariants(Product $product, int $productIndex, array $stocks, float $price): void
    {
        $colors = ['Black', 'Ivory', 'Rose', 'Emerald', 'Navy'];
        $sizes = ['S', 'M', 'L', 'XL', 'One Size'];
        $keptSkus = [];

        foreach ($stocks as $variantIndex => $stock) {
            $number = $variantIndex + 1;
            $sku = sprintf('DEMO-P-%03d-V%02d', $productIndex, $number);
            $keptSkus[] = $sku;
            $overrideMeasurements = $productIndex % 5 === 0 && $number === 1
                ? ['weight_oz' => 18, 'length_in' => 12, 'width_in' => 9, 'height_in' => 4]
                : ['weight_oz' => null, 'length_in' => null, 'width_in' => null, 'height_in' => null];

            $product->variants()->updateOrCreate(
                ['sku' => $sku],
                [
                    'name' => $colors[$variantIndex % count($colors)].' / '.$sizes[$variantIndex % count($sizes)],
                    'price' => $number % 2 === 0 ? round($price + ($number * 2.5), 2) : null,
                    'stock_qty' => $stock,
                    'attributes' => [
                        'color' => $colors[$variantIndex % count($colors)],
                        'size' => $sizes[$variantIndex % count($sizes)],
                    ],
                    ...$overrideMeasurements,
                ]
            );
        }

        if ($keptSkus === []) {
            $product->variants()->delete();
        } else {
            $product->variants()->whereNotIn('sku', $keptSkus)->delete();
        }
    }

    private function seedProductGallery(Product $product, int $index): void
    {
        if ($index > 36 && $index % 12 !== 0) {
            return;
        }
        if ($index % 7 === 0) {
            return; // Intentional missing-image state.
        }

        $count = match (true) {
            $index === 5 => 8,
            $index <= 4 => 4,
            $index % 6 === 0 => 3,
            default => 1,
        };
        $this->attachDemoImages($product, 'product_images', $count, $product->name);
    }

    private function variantStocks(int $index, int $count): array
    {
        if ($count === 0) {
            return [];
        }
        if ($index % 11 === 0) {
            return array_fill(0, $count, 0);
        }
        if ($index % 9 === 0) {
            return array_pad([1], $count, 0);
        }

        return array_map(fn (int $position) => (($index + $position) % 18) + 2, range(1, $count));
    }

    private function simpleStock(int $index): int
    {
        return match (true) {
            $index % 11 === 0 => 0,
            $index % 9 === 0 => 1,
            $index % 8 === 0 => 999,
            default => (($index * 7) % 75) + 4,
        };
    }

    private function price(int $index): float
    {
        return match ($index) {
            6 => 1299.99,
            7 => 0.99,
            default => round(19.95 + (($index * 17.35) % 480), 2),
        };
    }

    private function measurements(int $index, string $status): array
    {
        if ($status === 'draft' && $index % 2 === 0) {
            return ['weight_oz' => null, 'length_in' => null, 'width_in' => null, 'height_in' => null];
        }
        if ($index === 31) {
            return ['weight_oz' => 80, 'length_in' => 24, 'width_in' => 18, 'height_in' => 12];
        }
        if ($index === 32) {
            return ['weight_oz' => 400, 'length_in' => 16, 'width_in' => 12, 'height_in' => 8];
        }

        return [
            'weight_oz' => 6 + ($index % 42),
            'length_in' => 7 + ($index % 7),
            'width_in' => 5 + ($index % 5),
            'height_in' => 1 + ($index % 4),
        ];
    }

    private function description(int $index): string
    {
        $base = 'A deterministic demonstration product used to test catalogue grids, product details, cart calculations, variants, discounts, inventory, shipping measurements, search, sorting, and pagination.';

        return match ($index) {
            1 => $base.' وصف عربي للتأكد من دعم اتجاه النص والترميز بشكل صحيح. ✨',
            2 => str_repeat($base.' ', 8),
            3 => $base.' It contains punctuation: commas, “quotes”, apostrophes, ampersands & emoji 🎁.',
            default => $base,
        };
    }
}
