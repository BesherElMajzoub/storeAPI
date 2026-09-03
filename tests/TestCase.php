<?php

namespace Tests;

use App\Models\Product;
use App\Services\EasyPostService;
use App\Services\ShippingQuoteService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createShippingQuote(Product $product, ?array $address = null, int $quantity = 1, float $amount = 0.0): array
    {
        $address ??= [
            'name' => 'Buyer',
            'line1' => '123 Main Street',
            'city' => 'Los Angeles',
            'state' => 'CA',
            'postal_code' => '90001',
            'country' => 'US',
        ];
        $items = [['product_id' => $product->id, 'quantity' => $quantity]];
        $rateId = 'rate_'.strtolower(fake()->unique()->bothify('????########'));
        $shipmentId = 'shp_'.strtolower(fake()->unique()->bothify('????########'));
        $rate = (object) [
            'id' => $rateId,
            'shipment_id' => $shipmentId,
            'carrier' => 'USPS',
            'service' => 'Priority',
            'rate' => number_format($amount, 2, '.', ''),
            'currency' => 'USD',
            'delivery_days' => 3,
        ];
        $shipment = (object) ['id' => $shipmentId, 'rates' => [$rate]];

        $easyPost = $this->mock(EasyPostService::class);
        $easyPost->shouldReceive('getShippingRates')->once()->andReturn($shipment);
        $easyPost->shouldReceive('retrieveRate')->zeroOrMoreTimes()->andReturn($rate);

        app(ShippingQuoteService::class)->quote($address, $items);

        return compact('address', 'items', 'rateId', 'shipmentId');
    }
}
