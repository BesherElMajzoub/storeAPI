<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicProductContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_products_in_inactive_categories_are_not_publicly_visible(): void
    {
        $activeCategory = Category::create([
            'name' => 'Active',
            'slug' => 'active',
            'is_active' => true,
        ]);
        $inactiveCategory = Category::create([
            'name' => 'Inactive',
            'slug' => 'inactive',
            'is_active' => false,
        ]);
        $visible = Product::factory()->create(['category_id' => $activeCategory->id]);
        $hidden = Product::factory()->create(['category_id' => $inactiveCategory->id]);

        $response = $this->getJson('/api/v1/products?per_page=100')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($visible->id));
        $this->assertFalse($ids->contains($hidden->id));
        $this->getJson("/api/v1/products/{$hidden->slug}")->assertNotFound();
    }

    public function test_in_stock_accepts_boolean_query_literals(): void
    {
        $category = Category::create([
            'name' => 'Stock',
            'slug' => 'stock',
            'is_active' => true,
        ]);
        $available = Product::factory()->create([
            'category_id' => $category->id,
            'stock_qty' => 5,
            'in_stock' => true,
        ]);
        $unavailable = Product::factory()->create([
            'category_id' => $category->id,
            'stock_qty' => 0,
            'in_stock' => false,
        ]);

        $inStock = $this->getJson('/api/v1/products?in_stock=true')->assertOk();
        $this->assertSame([$available->id], collect($inStock->json('data'))->pluck('id')->all());

        $outOfStock = $this->getJson('/api/v1/products?in_stock=false')->assertOk();
        $this->assertSame([$unavailable->id], collect($outOfStock->json('data'))->pluck('id')->all());
    }

    public function test_api_validation_returns_json_without_an_accept_header(): void
    {
        $this->get('/api/v1/products?per_page=invalid')
            ->assertUnprocessable()
            ->assertHeader('content-type', 'application/json')
            ->assertJsonValidationErrors('per_page');
    }

    public function test_products_can_be_fetched_by_a_bounded_comma_separated_slug_list(): void
    {
        $category = Category::create([
            'name' => 'Batch',
            'slug' => 'batch',
            'is_active' => true,
        ]);
        $first = Product::factory()->create(['category_id' => $category->id, 'slug' => 'batch-first']);
        $second = Product::factory()->create(['category_id' => $category->id, 'slug' => 'batch-second']);
        Product::factory()->create(['category_id' => $category->id, 'slug' => 'not-requested']);
        Product::factory()->create([
            'category_id' => $category->id,
            'slug' => 'draft-requested',
            'status' => 'draft',
        ]);

        $response = $this->getJson('/api/v1/products?slugs=batch-first,batch-second,draft-requested&per_page=100')
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$first->id, $second->id],
            collect($response->json('data'))->pluck('id')->all()
        );

        $tooMany = collect(range(1, 101))->map(fn (int $index) => "slug-{$index}")->implode(',');
        $this->getJson('/api/v1/products?slugs='.$tooMany)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slugs');
    }

    public function test_product_sitemap_feed_contains_only_public_products(): void
    {
        $activeCategory = Category::create(['name' => 'Sitemap', 'slug' => 'sitemap', 'is_active' => true]);
        $inactiveCategory = Category::create(['name' => 'Hidden', 'slug' => 'hidden-sitemap', 'is_active' => false]);
        $visible = Product::factory()->create(['category_id' => $activeCategory->id, 'slug' => 'visible-sitemap']);
        Product::factory()->create(['category_id' => $activeCategory->id, 'slug' => 'draft-sitemap', 'status' => 'draft']);
        Product::factory()->create(['category_id' => $inactiveCategory->id, 'slug' => 'inactive-sitemap']);

        $response = $this->getJson('/api/v1/products/sitemap')
            ->assertOk()
            ->assertJsonPath('meta.count', 1)
            ->assertJsonPath('data.0.slug', $visible->slug)
            ->assertJsonStructure(['data' => [['slug', 'updated_at']]]);

        $this->assertSame([$visible->slug], collect($response->json('data'))->pluck('slug')->all());
    }

    public function test_authenticated_user_can_merge_a_guest_wishlist_without_duplicates(): void
    {
        $activeCategory = Category::create(['name' => 'Wishlist', 'slug' => 'wishlist-merge', 'is_active' => true]);
        $inactiveCategory = Category::create(['name' => 'Wishlist Hidden', 'slug' => 'wishlist-hidden', 'is_active' => false]);
        $user = User::factory()->create();
        $existing = Product::factory()->create(['category_id' => $activeCategory->id]);
        $new = Product::factory()->create(['category_id' => $activeCategory->id]);
        $draft = Product::factory()->create(['category_id' => $activeCategory->id, 'status' => 'draft']);
        $inactive = Product::factory()->create(['category_id' => $inactiveCategory->id]);
        $user->wishlistItems()->create(['product_id' => $existing->id]);

        $payload = ['product_ids' => [$existing->id, $new->id, $draft->id, $inactive->id]];
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/wishlist/merge', $payload)
            ->assertOk()
            ->assertJsonPath('data.added_count', 1)
            ->assertJsonPath('data.existing_count', 1)
            ->assertJsonPath('data.wishlist_count', 2)
            ->assertJsonPath('data.rejected_ids', [$draft->id, $inactive->id]);

        $this->postJson('/api/v1/wishlist/merge', $payload)
            ->assertOk()
            ->assertJsonPath('data.added_count', 0)
            ->assertJsonPath('data.existing_count', 2)
            ->assertJsonPath('data.wishlist_count', 2);
    }
}
