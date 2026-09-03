<?php

use App\Exceptions\InsufficientStockException;
use App\Models\Order;
use App\Services\OrderInventoryService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$orderId = (int) ($argv[1] ?? 0);
$startAt = (float) ($argv[2] ?? microtime(true));

while (microtime(true) < $startAt) {
    usleep(1000);
}

try {
    app(OrderInventoryService::class)->reserveExistingOrder(Order::findOrFail($orderId));
    echo 'SUCCESS';
} catch (InsufficientStockException) {
    echo 'OUT_OF_STOCK';
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());
    exit(2);
}
