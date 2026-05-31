<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        // 1) Main Categories
        $categories = [
            [
                'name'             => 'Clothing',
                'slug'             => 'clothing',
                'meta_title'       => 'Fashion & Clothing Store',
                'meta_description' => 'Browse our latest collection of modern clothing and fashion apparel.',
                'is_active'        => true,
                'sort_order'       => 1,
            ],
            [
                'name'             => 'Accessories',
                'slug'             => 'accessories',
                'meta_title'       => 'Luxury Accessories Collection',
                'meta_description' => 'Elegant jewelry, belts, scarves, and accessories to complete your look.',
                'is_active'        => true,
                'sort_order'       => 2,
            ],
            [
                'name'             => 'Bags',
                'slug'             => 'bags',
                'meta_title'       => 'Premium Leather Bags',
                'meta_description' => 'Shop our handpicked collection of designer bags and handbags.',
                'is_active'        => true,
                'sort_order'       => 3,
            ],
            [
                'name'             => 'Shoes',
                'slug'             => 'shoes',
                'meta_title'       => 'Elegant Footwear & Shoes',
                'meta_description' => 'Find the perfect pair of sandals, heels, and casual shoes.',
                'is_active'        => true,
                'sort_order'       => 4,
            ],
            [
                'name'             => 'Perfumes',
                'slug'             => 'perfumes',
                'meta_title'       => 'Oud & Premium Perfumes',
                'meta_description' => 'Indulge in our exquisite collection of oriental oud and French perfumes.',
                'is_active'        => true,
                'sort_order'       => 5,
            ],
            [
                'name'             => 'Electronics',
                'slug'             => 'electronics',
                'meta_title'       => 'Smart Gadgets & Electronics',
                'meta_description' => 'Discover high-quality premium smartphones, laptops, and gadgets.',
                'is_active'        => true,
                'sort_order'       => 6,
            ],
            [
                'name'             => 'Empty Category',
                'slug'             => 'empty-category',
                'meta_title'       => 'Empty Store Category',
                'meta_description' => 'This category contains no products for empty state testing.',
                'is_active'        => true,
                'sort_order'       => 7,
            ],
            [
                'name'             => 'Inactive Category',
                'slug'             => 'inactive-category',
                'meta_title'       => 'Hidden Category',
                'meta_description' => 'This category is inactive/hidden from search and list filters.',
                'is_active'        =>        false,
                'sort_order'       => 8,
            ],
        ];

        foreach ($categories as $cat) {
            $category = Category::updateOrCreate(
                ['slug' => $cat['slug']],
                [
                    'name'             => $cat['name'],
                    'is_active'        => $cat['is_active'],
                    'sort_order'       => $cat['sort_order'],
                    'meta_title'       => $cat['meta_title'],
                    'meta_description' => $cat['meta_description'],
                    'image'            => $cat['slug'] . '.png',
                ]
            );

            $this->attachPlaceholderImage($category, 'category_image', $cat['name']);
        }

        // 2) Subcategories
        $clothing = Category::where('slug', 'clothing')->first();
        if ($clothing) {
            $subcategories = [
                [
                    'name'             => 'Dresses',
                    'slug'             => 'dresses',
                    'meta_title'       => 'Women Elegance Dresses',
                    'meta_description' => 'Beautiful evening, casual, and formal dresses.',
                    'parent_id'        => $clothing->id,
                    'is_active'        => true,
                    'sort_order'       => 1,
                ],
                [
                    'name'             => 'Abayas',
                    'slug'             => 'abayas',
                    'meta_title'       => 'Classic & Modern Abayas',
                    'meta_description' => 'Browse our premium selection of elegant black and colored abayas.',
                    'parent_id'        => $clothing->id,
                    'is_active'        => true,
                    'sort_order'       => 2,
                ]
            ];

            foreach ($subcategories as $subcat) {
                $category = Category::updateOrCreate(
                    ['slug' => $subcat['slug']],
                    [
                        'name'             => $subcat['name'],
                        'parent_id'        => $subcat['parent_id'],
                        'is_active'        => $subcat['is_active'],
                        'sort_order'       => $subcat['sort_order'],
                        'meta_title'       => $subcat['meta_title'],
                        'meta_description' => $subcat['meta_description'],
                        'image'            => $subcat['slug'] . '.png',
                    ]
                );

                $this->attachPlaceholderImage($category, 'category_image', $subcat['name']);
            }
        }
    }

    /**
     * Attach a placeholder image to the model's collection, falling back to local creation if offline.
     */
    private function attachPlaceholderImage(Category $model, string $collection, string $text): void
    {
        if ($model->getMedia($collection)->count() > 0) {
            return;
        }

        $url = "https://placehold.co/1200x600/3b82f6/ffffff.png?text=" . urlencode($text);
        try {
            $model->addMediaFromUrl($url)->toMediaCollection($collection);
        } catch (\Exception $e) {
            // Offline fallback: write 1x1 transparent PNG locally and attach
            $tempDir = storage_path('app/temp_media');
            if (!file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $filename = Str::slug($text) . '.png';
            $filePath = $tempDir . '/' . $filename;
            
            $pngContent = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=');
            file_put_contents($filePath, $pngContent);
            
            $model->addMedia($filePath)->toMediaCollection($collection);
        }
    }
}
