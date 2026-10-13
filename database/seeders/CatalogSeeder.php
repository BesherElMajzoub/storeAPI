<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Database\Seeders\Concerns\AttachesCatalogImages;
use Illuminate\Database\Seeder;

/**
 * The Otantik Queen showcase catalogue: women's modest fashion with real images.
 *
 *   php artisan db:seed --class=CatalogSeeder
 *
 * Data: database/seeders/data/catalog.php. Images: catalog-images/ (git-ignored,
 * override with CATALOG_IMAGES_PATH). Idempotent; products without images stay draft.
 * Not part of ProductionSeeder because the images are not in git.
 */
class CatalogSeeder extends Seeder
{
    use AttachesCatalogImages;

    public function run(): void
    {
        $data = require database_path('seeders/data/catalog.php');

        $categories = $this->seedCategories($data['categories']);
        $count = $this->seedProducts($data['products'], $categories);

        $this->command?->info(sprintf('Catalogue: %d categories, %d products.', count($categories), $count));
    }

    /** @return array<string, Category> keyed by slug */
    private function seedCategories(array $tree): array
    {
        $created = [];

        foreach ($tree as $rootOrder => $rootData) {
            $root = Category::updateOrCreate(['slug' => $rootData['slug']], [
                'name' => $rootData['name'],
                'description' => $rootData['description'],
                'parent_id' => null,
                'is_active' => true,
                'sort_order' => $rootOrder + 1,
                'meta_title' => $rootData['name'].' | Otantik Queen',
                'meta_description' => $rootData['description'],
            ]);
            $banner = $this->imagesPath('categories'.DIRECTORY_SEPARATOR.$rootData['image']);
            $this->syncCategoryImage($root, $banner);
            $created[$root->slug] = $root;

            foreach ($rootData['children'] as $childOrder => $childData) {
                $child = Category::updateOrCreate(['slug' => $childData['slug']], [
                    'name' => $childData['name'],
                    'description' => $childData['description'],
                    'parent_id' => $root->id,
                    'is_active' => true,
                    'sort_order' => $childOrder + 1,
                    'meta_title' => $childData['name'].' | Otantik Queen',
                    'meta_description' => $childData['description'],
                ]);
                $this->syncCategoryImage($child, $banner); // children reuse the parent banner
                $created[$child->slug] = $child;
            }
        }

        return $created;
    }

    private function syncCategoryImage(Category $category, string $file): void
    {
        if (is_file($file)) {
            $this->syncImages($category, 'category_image', [$file], $category->name);
        } else {
            $this->command?->warn("Missing category image: {$file}");
        }
    }

    /** @param  array<string, Category>  $categories */
    private function seedProducts(array $products, array $categories): int
    {
        foreach ($products as $index => $data) {
            $images = $this->productImageFiles($data['slug']);
            if ($images === []) {
                $this->command?->warn("No images for {$data['slug']}: saved as draft.");
            }

            $variants = $this->variantRows($data);
            $stock = $variants === [] ? $this->simpleStock($index) : array_sum(array_column($variants, 'stock_qty'));
            [$length, $width, $height] = $data['dim'];

            $product = Product::updateOrCreate(['sku' => 'OQ-'.$data['code']], [
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'],
                'price' => $data['price'],
                'discount_price' => $data['discount'],
                'stock_qty' => $stock,
                'weight_oz' => $data['oz'],
                'length_in' => $length,
                'width_in' => $width,
                'height_in' => $height,
                'status' => $images === [] ? 'draft' : 'published',
                'category_id' => $categories[$data['category']]->id,
                'options' => $this->options($data),
                'in_stock' => $stock > 0,
                'is_featured' => $data['featured'],
                'meta_title' => $data['name'].' | Otantik Queen',
                'meta_description' => mb_substr($data['description'], 0, 250),
            ]);

            $this->syncVariants($product, $variants);
            $this->syncImages($product, 'product_images', $images, $data['name']);
        }

        return count($products);
    }

    /** @return array<string, list<string>>|null */
    private function options(array $data): ?array
    {
        $options = array_filter([
            'Color' => $data['colors'],
            'Size' => $data['sizes'],
        ]);

        return $options === [] ? null : $options;
    }

    /** @return list<array{name: string, sku: string, stock_qty: int, attributes: array<string, string>}> */
    private function variantRows(array $data): array
    {
        $colors = $data['colors'] ?: [null];
        $sizes = $data['sizes'] ?: [null];
        if ($data['colors'] === [] && $data['sizes'] === []) {
            return [];
        }

        $rows = [];
        foreach ($colors as $colorIndex => $color) {
            foreach ($sizes as $sizeIndex => $size) {
                $parts = array_filter([$color, $size]);
                $attributes = array_filter(['color' => $color, 'size' => $size]);
                $skuTail = collect($parts)->map(fn ($part) => strtoupper(str($part)->slug()))->implode('-');

                $rows[] = [
                    'name' => implode(' / ', $parts),
                    'sku' => 'OQ-'.$data['code'].'-'.$skuTail,
                    // Deterministic spread of stock, with a couple of low-stock rows for realism.
                    'stock_qty' => 4 + (($colorIndex * 5 + $sizeIndex * 3 + strlen($data['slug'])) % 17),
                    'attributes' => $attributes,
                ];
            }
        }

        return $rows;
    }

    private function syncVariants(Product $product, array $rows): void
    {
        foreach ($rows as $row) {
            $product->variants()->updateOrCreate(['sku' => $row['sku']], [
                'name' => $row['name'],
                'price' => null,
                'stock_qty' => $row['stock_qty'],
                'attributes' => $row['attributes'],
            ]);
        }

        $product->variants()->whereNotIn('sku', array_column($rows, 'sku'))->delete();
    }

    private function simpleStock(int $index): int
    {
        return 40 + ($index * 7) % 60;
    }
}
