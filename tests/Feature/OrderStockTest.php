<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;
    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        
        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'price' => 100.00,
            'stock_qty' => 10,
            'in_stock' => true,
            'status' => 'published',
        ]);

        $this->variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'name' => 'Test Variant',
            'sku' => 'TEST-VAR-1',
            'price' => 100.00,
            'stock_qty' => 5,
        ]);
    }

    public function test_stock_decrements_when_order_is_accepted_processing(): void
    {
        // 1. Create order in pending state
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        // Create order item
        $order->items()->create([
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'product_name' => $this->product->name,
            'variant_name' => $this->variant->name,
            'price' => 100.00,
            'quantity' => 2,
            'total' => 200.00,
        ]);

        // Assert initial stock
        $this->assertEquals(10, $this->product->fresh()->stock_qty);
        $this->assertEquals(5, $this->variant->fresh()->stock_qty);

        // 2. Accept order (change status to processing)
        $order->update(['status' => 'processing']);

        // Assert stock decremented by 2
        $this->assertEquals(8, $this->product->fresh()->stock_qty);
        $this->assertEquals(3, $this->variant->fresh()->stock_qty);
        $this->assertTrue($this->product->fresh()->in_stock);
    }

    public function test_product_in_stock_becomes_false_when_stock_reaches_zero(): void
    {
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 100.00,
            'quantity' => 10,
            'total' => 1000.00,
        ]);

        $order->update(['status' => 'processing']);

        $this->assertEquals(0, $this->product->fresh()->stock_qty);
        $this->assertFalse($this->product->fresh()->in_stock);
    }

    public function test_stock_restocks_when_processing_order_is_cancelled(): void
    {
        // 1. Start with processing order (stock already decremented)
        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'processing',
        ]);

        $order->items()->create([
            'product_id' => $this->product->id,
            'variant_id' => $this->variant->id,
            'product_name' => $this->product->name,
            'variant_name' => $this->variant->name,
            'price' => 100.00,
            'quantity' => 3,
            'total' => 300.00,
        ]);

        // Manually adjust stock for this test to match "already decremented" starting condition
        $this->product->update(['stock_qty' => 7]);
        $this->variant->update(['stock_qty' => 2]);

        // 2. Cancel order
        $order->update(['status' => 'cancelled']);

        // Assert stock is restored/incremented
        $this->assertEquals(10, $this->product->fresh()->stock_qty);
        $this->assertEquals(5, $this->variant->fresh()->stock_qty);
        $this->assertTrue($this->product->fresh()->in_stock);
    }
}
