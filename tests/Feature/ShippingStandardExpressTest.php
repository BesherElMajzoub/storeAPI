<?php

namespace Tests\Feature;

use App\Contracts\EasyPostServiceInterface;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingStandardExpressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.easypost.usps_carrier_account_id' => 'ca_usps_test',
            'services.easypost.ups_carrier_account_id' => 'ca_ups_test',
        ]);
    }

    public function test_rate_request_is_restricted_to_the_configured_usps_and_ups_carrier_accounts(): void
    {
        $product = Product::factory()->create();
        $shipment = (object) ['id' => 'shp_carriers', 'rates' => [
            (object) ['id' => 'rate_usps', 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'rate' => '5.00', 'currency' => 'USD', 'delivery_days' => 4],
        ]];

        $this->mock(EasyPostServiceInterface::class, function ($mock) use ($shipment) {
            $mock->shouldReceive('getShippingRates')
                ->once()
                ->withArgs(fn ($address, $parcel, $carrierAccounts) => $carrierAccounts === ['ca_usps_test', 'ca_ups_test'])
                ->andReturn($shipment);
        });

        $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk();
    }

    public function test_cheapest_usps_rate_becomes_standard_and_fastest_ups_rate_becomes_express(): void
    {
        $product = Product::factory()->create();
        $shipment = (object) ['id' => 'shp_bucket', 'rates' => [
            (object) ['id' => 'rate_usps_cheap', 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'rate' => '6.00', 'currency' => 'USD', 'delivery_days' => 5],
            (object) ['id' => 'rate_usps_pricey', 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => '9.00', 'currency' => 'USD', 'delivery_days' => 3],
            (object) ['id' => 'rate_ups_slow', 'carrier' => 'UPS', 'service' => 'Ground', 'rate' => '12.00', 'currency' => 'USD', 'delivery_days' => 4],
            (object) ['id' => 'rate_ups_fast', 'carrier' => 'UPS', 'service' => 'Next Day Air', 'rate' => '30.00', 'currency' => 'USD', 'delivery_days' => 1],
        ]];

        $this->mock(EasyPostServiceInterface::class, fn ($mock) => $mock->shouldReceive('getShippingRates')->once()->andReturn($shipment));

        $response = $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);

        $standard = collect($data)->firstWhere('method', 'standard');
        $express = collect($data)->firstWhere('method', 'express');

        $this->assertSame('rate_usps_cheap', $standard['rate_id']);
        $this->assertSame('rate_ups_fast', $express['rate_id']);
    }

    public function test_falls_back_to_cheapest_and_fastest_overall_when_a_carrier_is_missing_from_the_response(): void
    {
        $product = Product::factory()->create();
        $shipment = (object) ['id' => 'shp_fallback', 'rates' => [
            (object) ['id' => 'rate_fedex_cheap', 'carrier' => 'FedEx', 'service' => 'Ground', 'rate' => '7.00', 'currency' => 'USD', 'delivery_days' => 5],
            (object) ['id' => 'rate_fedex_fast', 'carrier' => 'FedEx', 'service' => 'Overnight', 'rate' => '20.00', 'currency' => 'USD', 'delivery_days' => 1],
        ]];

        $this->mock(EasyPostServiceInterface::class, fn ($mock) => $mock->shouldReceive('getShippingRates')->once()->andReturn($shipment));

        $response = $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('rate_fedex_cheap', collect($data)->firstWhere('method', 'standard')['rate_id']);
        $this->assertSame('rate_fedex_fast', collect($data)->firstWhere('method', 'express')['rate_id']);
    }

    public function test_only_one_option_is_returned_when_only_one_distinct_rate_is_available(): void
    {
        $product = Product::factory()->create();
        $shipment = (object) ['id' => 'shp_single', 'rates' => [
            (object) ['id' => 'rate_only', 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'rate' => '5.50', 'currency' => 'USD', 'delivery_days' => 4],
        ]];

        $this->mock(EasyPostServiceInterface::class, fn ($mock) => $mock->shouldReceive('getShippingRates')->once()->andReturn($shipment));

        $response = $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('standard', $data[0]['method']);
        $this->assertSame('rate_only', $data[0]['rate_id']);
    }

    private function address(): array
    {
        return [
            'name' => 'Buyer', 'line1' => '123 Main St', 'city' => 'Pasadena', 'state' => 'CA',
            'postal_code' => '91101', 'country' => 'US',
        ];
    }
}
