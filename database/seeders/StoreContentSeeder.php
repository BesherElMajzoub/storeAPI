<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class StoreContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Creating Store Content (Categories, Products, Variants, Media)...');

        // ─────────────────────────────────────────────────────────────────
        // 1. Categories
        // ─────────────────────────────────────────────────────────────────
        $electronics = Category::firstOrCreate(
            ['slug' => 'electronics'],
            [
                'name' => 'Electronics',
                'is_active' => true,
                'meta_description' => 'Modern electronics and gadgets.',
            ]
        );
        $this->seedMediaFromUrl($electronics, 'category_image', 'https://placehold.co/1200x600/111827/ffffff.png?text=Electronics');

        $clothing = Category::firstOrCreate(
            ['slug' => 'clothing'],
            [
                'name' => 'Clothing',
                'is_active' => true,
                'meta_description' => 'Fashion and apparel.',
            ]
        );
        $this->seedMediaFromUrl($clothing, 'category_image', 'https://placehold.co/1200x600/8b5cf6/ffffff.png?text=Clothing');

        // ─────────────────────────────────────────────────────────────────
        // 2. Products - Electronics
        // ─────────────────────────────────────────────────────────────────
        $phone = Product::firstOrCreate(
            ['slug' => 'smartphone-x-pro'],
            [
                'name'           => 'Smartphone X Pro',
                'description'    => 'The latest smartphone with incredible AI features, advanced camera system, and all-day battery life.',
                'price'          => 1099.00,
                'discount_price' => 999.00,
                'sku'            => 'ELEC-PH-XPRO',
                'stock_qty'      => 50,
                'status'         => 'published',
                'category_id'    => $electronics->id,
                'in_stock'       => true,
                'is_featured'    => true,
                'rating'         => 4.8,
                'reviews_count'  => 124,
                'options'        => ['Color' => ['Midnight Black', 'Starlight White'], 'Storage' => ['256GB', '512GB']],
            ]
        );

        $this->seedMediaFromUrl($phone, 'product_images', 'https://placehold.co/1400x1400/0f172a/ffffff.png?text=Phone+Front');
        $this->seedMediaFromUrl($phone, 'product_images', 'https://placehold.co/1400x1400/1e293b/ffffff.png?text=Phone+Back');

        // Variants for Phone
        if ($phone->variants()->count() === 0) {
            $phone->variants()->createMany([
                ['name' => 'Midnight Black / 256GB', 'sku' => 'ELEC-PH-XPRO-BLK-256', 'price' => 1099.00, 'stock_qty' => 20, 'attributes' => ['Color' => 'Midnight Black', 'Storage' => '256GB']],
                ['name' => 'Midnight Black / 512GB', 'sku' => 'ELEC-PH-XPRO-BLK-512', 'price' => 1199.00, 'stock_qty' => 10, 'attributes' => ['Color' => 'Midnight Black', 'Storage' => '512GB']],
                ['name' => 'Starlight White / 256GB', 'sku' => 'ELEC-PH-XPRO-WHT-256', 'price' => 1099.00, 'stock_qty' => 15, 'attributes' => ['Color' => 'Starlight White', 'Storage' => '256GB']],
            ]);
        }

        // ─────────────────────────────────────────────────────────────────
        // 3. Products - Clothing
        // ─────────────────────────────────────────────────────────────────
        $shirt = Product::firstOrCreate(
            ['slug' => 'premium-cotton-tshirt'],
            [
                'name'           => 'Premium Cotton T-Shirt',
                'description'    => 'Ultra-soft, breathable 100% organic cotton t-shirt. Perfect for everyday wear.',
                'price'          => 35.00,
                'discount_price' => null,
                'sku'            => 'CLO-TS-PRM',
                'stock_qty'      => 200,
                'status'         => 'published',
                'category_id'    => $clothing->id,
                'in_stock'       => true,
                'is_featured'    => false,
                'rating'         => 4.5,
                'reviews_count'  => 89,
                'options'        => ['Color' => ['Red', 'Navy'], 'Size' => ['S', 'M', 'L', 'XL']],
            ]
        );

        $this->seedMediaFromUrl($shirt, 'product_images', 'https://placehold.co/1400x1400/b91c1c/ffffff.png?text=Red+T-Shirt');
        $this->seedMediaFromUrl($shirt, 'product_images', 'https://placehold.co/1400x1400/1e3a8a/ffffff.png?text=Navy+T-Shirt');

        // Variants for Shirt
        if ($shirt->variants()->count() === 0) {
            $shirt->variants()->createMany([
                ['name' => 'Red / M', 'sku' => 'CLO-TS-PRM-RED-M', 'price' => 35.00, 'stock_qty' => 50, 'attributes' => ['Color' => 'Red', 'Size' => 'M']],
                ['name' => 'Red / L', 'sku' => 'CLO-TS-PRM-RED-L', 'price' => 35.00, 'stock_qty' => 50, 'attributes' => ['Color' => 'Red', 'Size' => 'L']],
                ['name' => 'Navy / M', 'sku' => 'CLO-TS-PRM-NVY-M', 'price' => 35.00, 'stock_qty' => 50, 'attributes' => ['Color' => 'Navy', 'Size' => 'M']],
                ['name' => 'Navy / L', 'sku' => 'CLO-TS-PRM-NVY-L', 'price' => 35.00, 'stock_qty' => 50, 'attributes' => ['Color' => 'Navy', 'Size' => 'L']],
            ]);
        }

        $this->command->info('Store content seeded successfully.');
    }

    /**
     * Helper to safely seed media from a URL.
     */
    private function seedMediaFromUrl($model, string $collection, string $url): void
    {
        if ($model->getMedia($collection)->count() > 0) {
            return; // Already has media
        }

        try {
            $model->addMediaFromUrl($url)
                  ->toMediaCollection($collection);
        } catch (\Exception $e) {
            $this->command->warn("Failed to download media for {$model->name}: " . $e->getMessage());
        }
    }
}
