<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));

        return $admin;
    }

    public function test_dashboard_counts_orders_still_awaiting_payment(): void
    {
        // Real, unambiguous awaiting-payment orders (the only status the
        // checkout flow actually creates before payment completes).
        Order::factory()->count(3)->create([
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
        ]);
        // Not awaiting payment — must not be counted.
        Order::factory()->create(['status' => 'processing', 'payment_status' => 'paid']);
        Order::factory()->create(['status' => 'cancelled', 'payment_status' => 'failed']);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $response->assertJsonPath('current_orders_count', 3);
        $response->assertJsonPath('alerts.pending_orders', 3);
    }

    public function test_dashboard_month_sales_total_excludes_unpaid_and_cancelled_orders(): void
    {
        Order::factory()->create(['status' => 'processing', 'payment_status' => 'paid', 'total' => 100]);
        Order::factory()->create(['status' => 'delivered', 'payment_status' => 'paid', 'total' => 50]);
        Order::factory()->create(['status' => 'pending_payment', 'payment_status' => 'unpaid', 'total' => 999]);
        Order::factory()->create(['status' => 'cancelled', 'payment_status' => 'failed', 'total' => 999]);
        Order::factory()->create(['status' => 'refunded', 'payment_status' => 'refunded', 'total' => 999]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $response->assertJsonPath('month_sales_total', '150.00');
    }

    public function test_dashboard_low_stock_alert_counts_products_under_the_threshold(): void
    {
        Product::factory()->create(['stock_qty' => 0]);
        Product::factory()->create(['stock_qty' => 2]);
        Product::factory()->create(['stock_qty' => 3]);
        Product::factory()->create(['stock_qty' => 50]);

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk();

        $response->assertJsonPath('alerts.low_stock', 2);
    }
}
