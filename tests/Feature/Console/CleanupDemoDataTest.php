<?php

namespace Tests\Feature\Console;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanupDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_targets_without_deleting_them(): void
    {
        $category = Category::create(['name' => 'Demo', 'slug' => 'demo-root']);
        $product = Product::factory()->create([
            'category_id' => $category->id,
            'slug' => 'demo-product-001-test',
            'sku' => 'DEMO-P-001',
        ]);

        $this->artisan('app:cleanup-demo-data')->assertSuccessful();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
    }

    public function test_execution_requires_a_backup_reference(): void
    {
        $this->artisan('app:cleanup-demo-data', ['--execute' => true])
            ->expectsOutputToContain('requires --backup-reference')
            ->assertFailed();
    }

    public function test_execution_deletes_recognized_demo_data_and_preserves_real_data(): void
    {
        $demoUser = User::factory()->create(['email' => 'customer@demo.test']);
        $realUser = User::factory()->create();
        $demoCategory = Category::create(['name' => 'Demo', 'slug' => 'demo-root']);
        $realCategory = Category::create(['name' => 'Real', 'slug' => 'real-root']);
        $demoProduct = Product::factory()->create([
            'category_id' => $demoCategory->id,
            'slug' => 'demo-product-001-test',
            'sku' => 'DEMO-P-001',
        ]);
        $realProduct = Product::factory()->create(['category_id' => $realCategory->id]);
        $demoOrder = Order::factory()->for($demoUser)->create(['order_number' => 'DEMO-001']);
        $realOrder = Order::factory()->for($realUser)->create(['order_number' => 'ORD-REAL001']);

        $this->artisan('app:cleanup-demo-data', [
            '--execute' => true,
            '--backup-reference' => 'BACKUP-20261011',
        ])->assertSuccessful();

        $this->assertDatabaseMissing('products', ['id' => $demoProduct->id]);
        $this->assertDatabaseMissing('categories', ['id' => $demoCategory->id]);
        $this->assertDatabaseMissing('orders', ['id' => $demoOrder->id]);
        $this->assertDatabaseMissing('users', ['id' => $demoUser->id]);
        $this->assertDatabaseHas('products', ['id' => $realProduct->id]);
        $this->assertDatabaseHas('orders', ['id' => $realOrder->id]);
    }

    public function test_non_demo_dependency_blocks_execution_before_any_delete(): void
    {
        $demoCategory = Category::create(['name' => 'Demo', 'slug' => 'demo-root']);
        $realProduct = Product::factory()->create(['category_id' => $demoCategory->id]);

        $this->artisan('app:cleanup-demo-data', [
            '--execute' => true,
            '--backup-reference' => 'BACKUP-20261011',
        ])->expectsOutputToContain('Blocked: demo categories contain non-demo products')
            ->assertFailed();

        $this->assertDatabaseHas('products', ['id' => $realProduct->id]);
        $this->assertDatabaseHas('categories', ['id' => $demoCategory->id]);
    }

    public function test_non_demo_product_is_deleted_only_when_explicitly_approved(): void
    {
        $product = Product::factory()->create();

        $this->artisan('app:cleanup-demo-data', [
            '--execute' => true,
            '--backup-reference' => 'BACKUP-20261011',
            '--product' => [$product->id],
        ])->assertSuccessful();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
    }
}
