<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CancellationRequestInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepting_a_cancellation_releases_reserved_inventory_via_the_order_observer(): void
    {
        Mail::fake();
        $customer = User::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $product = Product::factory()->create(['stock_qty' => 10, 'in_stock' => true]);
        $order = Order::factory()->for($customer)->create(['status' => 'pending']);
        $order->items()->create([
            'product_id' => $product->id,
            'product_name' => $product->name,
            'sku' => $product->sku,
            'price' => 20,
            'quantity' => 2,
            'total' => 40,
        ]);
        $order->update(['status' => 'processing']);
        $this->assertSame(8, $product->fresh()->stock_qty);

        $cancellation = OrderCancellationRequest::create([
            'order_id' => $order->id,
            'user_id' => $customer->id,
            'reason' => 'Changed my mind.',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/cancellation-requests/{$cancellation->id}/accept")
            ->assertOk();

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(10, $product->fresh()->stock_qty);
        $this->assertNotNull($order->fresh()->stock_released_at);
    }
}
