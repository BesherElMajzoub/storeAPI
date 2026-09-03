<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

class ConcurrentInventoryTest extends TestCase
{
    use DatabaseTruncation;

    public function test_two_concurrent_orders_for_the_last_unit_allow_exactly_one_reservation(): void
    {
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is required for the real concurrency check.');
        }

        $product = Product::factory()->create(['stock_qty' => 1, 'in_stock' => true]);
        $orders = collect([1, 2])->map(function () use ($product) {
            $order = Order::factory()->for(User::factory())->create(['status' => 'pending']);
            $order->items()->create([
                'product_id' => $product->id,
                'product_name' => $product->name,
                'price' => $product->price,
                'quantity' => 1,
                'total' => $product->price,
            ]);

            return $order;
        });

        $startAt = microtime(true) + 1.0;
        $workers = $orders->map(fn (Order $order) => $this->startWorker($order->id, $startAt));
        $outputs = $workers->map(function (array $worker) {
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            $exit = proc_close($worker['process']);
            $this->assertSame(0, $exit, $stderr);

            return trim($stdout);
        });

        $this->assertSame(1, $outputs->filter(fn ($value) => $value === 'SUCCESS')->count());
        $this->assertSame(1, $outputs->filter(fn ($value) => $value === 'OUT_OF_STOCK')->count());
        $this->assertSame(0, $product->fresh()->stock_qty);
        $this->assertSame(1, Order::query()->whereNotNull('stock_reserved_at')->count());
    }

    private function startWorker(int $orderId, float $startAt): array
    {
        $environment = array_merge(getenv(), [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => (string) config('database.default'),
            'DB_HOST' => (string) config('database.connections.'.config('database.default').'.host'),
            'DB_PORT' => (string) config('database.connections.'.config('database.default').'.port'),
            'DB_DATABASE' => (string) config('database.connections.'.config('database.default').'.database'),
            'DB_USERNAME' => (string) config('database.connections.'.config('database.default').'.username'),
            'DB_PASSWORD' => (string) config('database.connections.'.config('database.default').'.password'),
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ]);
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open([
            PHP_BINARY,
            base_path('tests/Support/reserve-order-worker.php'),
            (string) $orderId,
            (string) $startAt,
        ], $descriptors, $pipes, base_path(), $environment);

        $this->assertIsResource($process);
        fclose($pipes[0]);

        return compact('process', 'pipes');
    }
}
