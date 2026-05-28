<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);
        return [
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(5),
            'description' => fake()->paragraph(),
            'price' => fake()->randomFloat(2, 10, 500),
            'discount_price' => null,
            'sku' => 'SKU-' . strtoupper(Str::random(8)),
            'stock_qty' => fake()->numberBetween(10, 100),
            'status' => 'published',
            'in_stock' => true,
            'is_featured' => false,
        ];
    }
}
