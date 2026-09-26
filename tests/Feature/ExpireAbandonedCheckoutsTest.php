<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class ExpireAbandonedCheckoutsTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_stale_checkout_is_expired_cancelled_and_released_once(): void
    {
        $product = Product::factory()->create(['stock_qty' => 4, 'in_stock' => true]);
        $order = Order::factory()->create([
            'status' => 'pending_payment', 'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_stale_open', 'created_at' => now()->subHours(2),
            'stock_reserved_at' => now()->subHours(2),
        ]);
        $order->items()->create([
            'product_id' => $product->id, 'product_name' => $product->name,
            'price' => 6, 'quantity' => 1, 'total' => 6,
        ]);
        $product->update(['stock_qty' => 3]);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrieveCheckoutSession')->once()->with('cs_stale_open')
                ->andReturn(StripeSession::constructFrom(['id' => 'cs_stale_open', 'status' => 'open']));
            $mock->shouldReceive('expireCheckoutSession')->once()->with('cs_stale_open')
                ->andReturn(StripeSession::constructFrom(['id' => 'cs_stale_open', 'status' => 'expired']));
        });

        Artisan::call('orders:expire-abandoned-checkouts');
        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('failed', $order->payment_status);
        $this->assertSame(4, (int) $product->fresh()->stock_qty);

        Artisan::call('orders:expire-abandoned-checkouts');
        $this->assertSame(4, (int) $product->fresh()->stock_qty);
    }

    public function test_complete_stale_checkout_is_left_for_webhook_confirmation(): void
    {
        $order = Order::factory()->create([
            'status' => 'pending_payment', 'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_stale_complete', 'created_at' => now()->subHours(2),
        ]);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrieveCheckoutSession')->once()->with('cs_stale_complete')
                ->andReturn(StripeSession::constructFrom(['id' => 'cs_stale_complete', 'status' => 'complete']));
            $mock->shouldNotReceive('expireCheckoutSession');
        });

        Artisan::call('orders:expire-abandoned-checkouts');
        $this->assertSame('pending_payment', $order->fresh()->status);
    }

    public function test_provider_error_leaves_stale_checkout_untouched_for_retry(): void
    {
        $order = Order::factory()->create([
            'status' => 'pending_payment', 'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_provider_error', 'created_at' => now()->subHours(2),
        ]);

        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('retrieveCheckoutSession')->once()->andThrow(new \RuntimeException('provider down'));
            $mock->shouldNotReceive('expireCheckoutSession');
        });

        Artisan::call('orders:expire-abandoned-checkouts');
        $this->assertSame('pending_payment', $order->fresh()->status);
    }
}
