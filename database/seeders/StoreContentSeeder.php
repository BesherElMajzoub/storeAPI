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
        // Categories
        $electronics = Category::create([
            'name' => 'Electronics',
            'slug' => 'electronics',
            'is_active' => true
        ]);
        
        $clothing = Category::create([
            'name' => 'Clothing',
            'slug' => 'clothing',
            'is_active' => true
        ]);

        // Products
        $p1 = Product::create([
            'name' => 'Smartphone X',
            'slug' => 'smartphone-x',
            'description' => 'Latest smartphone with AI.',
            'price' => 999.00,
            'discount_price' => 899.00,
            'sku' => 'PHONE-X-001',
            'stock_qty' => 50,
            'status' => 'published',
            'category_id' => $electronics->id,
            'in_stock' => true
        ]);
        
        $p1->images()->create([
            'url' => 'https://placehold.co/600x400'
        ]);
        
        Product::create([
            'name' => 'Classic T-Shirt',
            'slug' => 'classic-t-shirt',
            'description' => '100% Cotton.',
            'price' => 29.99,
            'sku' => 'SHIRT-001',
            'stock_qty' => 100,
            'status' => 'published',
            'category_id' => $clothing->id,
            'options' => ['Color' => ['Red', 'Blue'], 'Size' => ['M', 'L']],
            'in_stock' => true
        ]);
    }
}
