<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductContractTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::create(['name' => 'Admin']));
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_product_metadata_fields_are_persisted_and_returned(): void
    {
        $response = $this->postJson('/api/v1/admin/products', [
            'name' => 'Contract Product',
            'price' => 100,
            'discount_price' => 80,
            'stock_qty' => 5,
            'in_stock' => true,
            'status' => 'published',
            'meta_title' => 'Contract title',
            'meta_description' => 'Contract description',
        ])->assertCreated();

        $response
            ->assertJsonPath('data.discount_price', 80)
            ->assertJsonPath('data.in_stock', true)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.meta_title', 'Contract title')
            ->assertJsonPath('data.meta_description', 'Contract description');

        $this->assertDatabaseHas('products', [
            'id' => $response->json('data.id'),
            'meta_title' => 'Contract title',
            'meta_description' => 'Contract description',
        ]);
    }

    public function test_status_enum_is_enforced_on_create_and_update(): void
    {
        $this->postJson('/api/v1/admin/products', [
            'name' => 'Bad status', 'price' => 10, 'status' => 'visible',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $product = Product::factory()->create();
        $this->patchJson("/api/v1/admin/products/{$product->id}", ['status' => 'visible'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_admin_product_filters_and_sorting_contract(): void
    {
        $category = Category::create(['name' => 'Rings', 'slug' => 'rings']);
        Product::factory()->create(['name' => 'Zulu Ring', 'sku' => 'RING-Z', 'category_id' => $category->id, 'price' => 30, 'stock_qty' => 2, 'status' => 'draft', 'is_featured' => true]);
        Product::factory()->create(['name' => 'Alpha Ring', 'sku' => 'RING-A', 'category_id' => $category->id, 'price' => 10, 'stock_qty' => 8, 'status' => 'draft', 'is_featured' => true]);
        Product::factory()->create(['name' => 'Other', 'status' => 'published', 'is_featured' => false]);

        $response = $this->getJson("/api/v1/admin/products?search=RING&category_id={$category->id}&status=draft&is_featured=true&sort=price_asc")
            ->assertOk();

        $this->assertSame(['Alpha Ring', 'Zulu Ring'], collect($response->json('data.data'))->pluck('name')->all());
    }

    public function test_variant_update_is_a_transactional_upsert_with_explicit_delete(): void
    {
        $product = Product::factory()->create(['stock_qty' => 50]);
        $updated = ProductVariant::create(['product_id' => $product->id, 'name' => 'Old', 'sku' => 'VAR-OLD', 'stock_qty' => 2]);
        $deleted = ProductVariant::create(['product_id' => $product->id, 'name' => 'Delete', 'sku' => 'VAR-DEL', 'stock_qty' => 1]);
        $kept = ProductVariant::create(['product_id' => $product->id, 'name' => 'Keep', 'sku' => 'VAR-KEEP', 'stock_qty' => 1]);

        $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'variants' => [
                ['id' => $updated->id, 'name' => 'Updated', 'sku' => 'VAR-UPD', 'stock_qty' => 3],
                ['id' => $deleted->id, '_delete' => true],
                ['name' => 'Created', 'sku' => 'VAR-NEW', 'stock_qty' => 4],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $updated->id, 'name' => 'Updated']);
        $this->assertDatabaseMissing('product_variants', ['id' => $deleted->id]);
        $this->assertDatabaseHas('product_variants', ['id' => $kept->id, 'name' => 'Keep']);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'sku' => 'VAR-NEW']);
    }

    public function test_variant_from_another_product_cannot_be_modified(): void
    {
        $product = Product::factory()->create();
        $other = Product::factory()->create();
        $variant = ProductVariant::create(['product_id' => $other->id, 'name' => 'Other', 'stock_qty' => 1]);

        $this->patchJson("/api/v1/admin/products/{$product->id}", [
            'variants' => [['id' => $variant->id, 'name' => 'Hijacked']],
        ])->assertUnprocessable()->assertJsonValidationErrors('variants.0.id');
    }

    public function test_bulk_update_is_atomic_and_allowlisted(): void
    {
        $products = Product::factory()->count(2)->create();

        $this->postJson('/api/v1/admin/products/bulk', [
            'ids' => $products->pluck('id')->all(),
            'set' => ['status' => 'archived', 'is_featured' => true],
        ])->assertOk();

        foreach ($products as $product) {
            $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'archived', 'is_featured' => true]);
        }

        $this->postJson('/api/v1/admin/products/bulk', [
            'ids' => $products->pluck('id')->all(),
            'set' => ['price' => 0],
        ])->assertUnprocessable();
    }
}
