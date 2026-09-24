<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\InspiredLead;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoAnalyticsSeeder;
use Database\Seeders\DemoCatalogSeeder;
use Database\Seeders\DemoCommerceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DemoDatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_dataset_covers_frontend_catalogue_commerce_and_empty_states(): void
    {
        Storage::fake('public');

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 24);
        $this->assertDatabaseCount('categories', 36);
        $this->assertDatabaseCount('products', DemoCatalogSeeder::PRODUCT_COUNT);
        $this->assertGreaterThan(200, ProductVariant::count());
        $this->assertGreaterThan(60, DB::table('media')->count());

        $this->assertDatabaseCount('orders', DemoCommerceSeeder::ORDER_COUNT);
        foreach (['pending_payment', 'pending', 'processing', 'shipped', 'delivered', 'cancelled', 'refunded'] as $status) {
            $this->assertDatabaseHas('orders', ['status' => $status]);
        }
        foreach (['unknown', 'pre_transit', 'in_transit', 'out_for_delivery', 'available_for_pickup', 'delivered', 'return_to_sender', 'failure', 'cancelled', 'error'] as $status) {
            $this->assertDatabaseHas('orders', ['shipment_status' => $status]);
        }

        $this->assertGreaterThan(80, Review::count());
        $this->assertGreaterThan(100, DB::table('wishlist_items')->count());
        $this->assertDatabaseCount('contact_messages', 32);
        $this->assertDatabaseCount('inspired_leads', 28);
        $this->assertDatabaseCount('visitors', DemoAnalyticsSeeder::VISITOR_COUNT);
        $this->assertDatabaseCount('visitor_sessions', DemoAnalyticsSeeder::SESSION_COUNT);
        $this->assertGreaterThan(350, DB::table('page_views')->count());
        $this->assertGreaterThan(200, DB::table('analytics_events')->count());

        $this->assertDatabaseHas('users', ['email' => 'customer.empty@demo.test']);
        $emptyUser = User::where('email', 'customer.empty@demo.test')->firstOrFail();
        $this->assertFalse($emptyUser->addresses()->exists());
        $this->assertFalse($emptyUser->wishlistItems()->exists());
        $this->assertFalse($emptyUser->orders()->exists());
        $this->assertDatabaseHas('categories', ['slug' => 'demo-frontend-states-empty-category', 'is_active' => true]);
        $emptyCategory = Category::where('slug', 'demo-frontend-states-empty-category')->firstOrFail();
        $this->assertFalse($emptyCategory->products()->exists());

        $this->assertTrue(Product::published()->whereNull('weight_oz')->doesntExist());
        $this->assertTrue(Product::published()->whereNull('length_in')->doesntExist());
        $this->assertDatabaseHas('products', ['sku' => 'DEMO-P-031', 'status' => 'published', 'length_in' => 24]);
        $this->assertDatabaseHas('products', ['stock_qty' => 0, 'in_stock' => false]);
        $this->assertDatabaseHas('products', ['status' => 'draft']);
        $this->assertDatabaseHas('products', ['status' => 'archived']);
        $this->assertDatabaseHas('contact_messages', ['status' => 'new']);
        $this->assertDatabaseHas('contact_messages', ['status' => 'archived']);
        $this->assertGreaterThan(0, ContactMessage::whereRaw('CHAR_LENGTH(message) > 500')->count());
        $this->assertGreaterThan(0, InspiredLead::whereNull('name')->count());
        $this->assertGreaterThan(0, Order::whereNotNull('tracking_events')->count());

        $this->getJson('/api/v1/products?per_page=12')
            ->assertOk()
            ->assertJsonCount(12, 'data');
        $this->getJson('/api/v1/products/demo-product-001-dress')
            ->assertOk()
            ->assertJsonCount(4, 'data.gallery')
            ->assertJsonCount(3, 'data.variants');

        $trackedOrder = Order::with('user')->where('order_number', 'DEMO-000003')->firstOrFail();
        $this->postJson('/api/v1/orders/track', [
            'order_number' => $trackedOrder->order_number,
            'email' => $trackedOrder->user->email,
        ])->assertOk()
            ->assertJsonPath('data.order_number', 'DEMO-000003')
            ->assertJsonMissingPath('data.shipping_address');

        $this->actingAs($trackedOrder->user, 'sanctum')
            ->getJson('/api/v1/orders/'.$trackedOrder->id)
            ->assertOk()
            ->assertJsonPath('data.shipment.tracking_number', $trackedOrder->tracking_number)
            ->assertJsonMissingPath('data.shipment.label_url');

        $admin = User::where('email', 'admin@demo.test')->firstOrFail();
        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/orders/'.$trackedOrder->id)
            ->assertOk()
            ->assertJsonPath('data.shipment.label_url', $trackedOrder->label_url);
    }
}
