<?php

namespace Tests\Feature;

use App\Contracts\EasyPostServiceInterface;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Checkout\Session as StripeSession;
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
        // Above the $100 threshold, so Standard is free and Express is not.
        $product = Product::factory()->create(['price' => 150.00, 'discount_price' => null]);
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
        $this->assertSame(0, $standard['amount']);
        $this->assertSame(6, $standard['carrier_amount']);
        $this->assertSame('threshold', $standard['free_shipping_reason']);
        $this->assertSame(30, $express['amount']);
        $this->assertSame(30, $express['carrier_amount']);
        $this->assertNull($express['free_shipping_reason']);
    }

    public function test_monetary_discount_is_applied_before_the_standard_threshold(): void
    {
        $product = Product::factory()->create(['price' => 120]);
        Coupon::create(['code' => 'SAVE30', 'type' => 'fixed', 'value' => 30, 'is_active' => true]);
        $this->mockTwoRates('discount');

        $data = $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'SAVE30',
        ])->assertOk()->json('data');

        $this->assertSame(6, collect($data)->firstWhere('method', 'standard')['amount']);
        $this->assertNull(collect($data)->firstWhere('method', 'standard')['free_shipping_reason']);
        $this->assertSame(30, collect($data)->firstWhere('method', 'express')['amount']);
    }

    public function test_free_shipping_coupon_waives_only_standard_below_the_threshold(): void
    {
        $product = Product::factory()->create(['price' => 60]);
        Coupon::create(['code' => 'FREESHIP', 'type' => 'free_shipping', 'value' => 0, 'is_active' => true]);
        $this->mockTwoRates('coupon');

        $data = $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'coupon_code' => 'FREESHIP',
        ])->assertOk()->json('data');

        $standard = collect($data)->firstWhere('method', 'standard');
        $express = collect($data)->firstWhere('method', 'express');
        $this->assertSame(0, $standard['amount']);
        $this->assertSame('coupon', $standard['free_shipping_reason']);
        $this->assertSame(30, $express['amount']);
        $this->assertNull($express['free_shipping_reason']);
    }

    public function test_order_creation_recalculates_and_preserves_express_price_above_threshold(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 120, 'stock_qty' => 5]);
        $shipment = (object) ['id' => 'shp_order_express', 'rates' => [
            (object) ['id' => 'rate_order_standard', 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'rate' => '6.00', 'currency' => 'USD', 'delivery_days' => 5],
            (object) ['id' => 'rate_order_express', 'carrier' => 'UPS', 'service' => 'Next Day Air', 'rate' => '30.00', 'currency' => 'USD', 'delivery_days' => 1],
        ]];

        $this->mock(EasyPostServiceInterface::class, function ($mock) use ($shipment): void {
            $mock->shouldReceive('getShippingRates')->once()->andReturn($shipment);
            $mock->shouldReceive('retrieveRate')->once()->with('rate_order_express')->andReturn((object) [
                'id' => 'rate_order_express', 'shipment_id' => 'shp_order_express',
                'carrier' => 'UPS', 'service' => 'Next Day Air', 'rate' => '30.00',
                'currency' => 'USD', 'delivery_days' => 1,
            ]);
        });
        $this->mock(StripeCheckoutService::class, fn ($mock) => $mock->shouldReceive('createCheckoutSession')->once()->andReturn(
            StripeSession::constructFrom(['id' => 'cs_express', 'url' => 'https://checkout.test/cs_express'])
        ));

        $rates = $this->actingAs($user, 'sanctum')->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk()->json('data');
        $express = collect($rates)->firstWhere('method', 'express');

        $this->postJson('/api/v1/orders', [
            'shipping_address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_rate_id' => $express['rate_id'],
        ])->assertCreated();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame('30.00', $order->shipping_cost);
        $this->assertSame('30.00', $order->carrier_shipping_cost);
        $this->assertNull($order->free_shipping_reason);
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

    public function test_ups_ground_is_never_express_even_with_a_one_day_eta(): void
    {
        $product = Product::factory()->create();
        $shipment = (object) ['id' => 'shp_ground', 'rates' => [
            (object) ['id' => 'rate_usps', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '5.00', 'currency' => 'USD', 'delivery_days' => 4],
            (object) ['id' => 'rate_ups_ground', 'carrier' => 'UPSDAP', 'service' => 'Ground', 'rate' => '6.80', 'currency' => 'USD', 'delivery_days' => 1],
            (object) ['id' => 'rate_ups_2day', 'carrier' => 'UPS', 'service' => '2ndDayAir', 'rate' => '22.00', 'currency' => 'USD', 'delivery_days' => 1],
        ]];

        $this->mock(EasyPostServiceInterface::class, fn ($mock) => $mock->shouldReceive('getShippingRates')->once()->andReturn($shipment));

        $data = $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk()->json('data');

        $this->assertSame('rate_ups_2day', collect($data)->firstWhere('method', 'express')['rate_id']);
    }

    public function test_express_is_omitted_when_only_ground_services_are_available(): void
    {
        $product = Product::factory()->create();
        $shipment = (object) ['id' => 'shp_only_ground', 'rates' => [
            (object) ['id' => 'rate_usps', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '5.00', 'currency' => 'USD', 'delivery_days' => 4],
            (object) ['id' => 'rate_ups_ground', 'carrier' => 'UPS', 'service' => 'Ground', 'rate' => '6.80', 'currency' => 'USD', 'delivery_days' => 1],
        ]];

        $this->mock(EasyPostServiceInterface::class, fn ($mock) => $mock->shouldReceive('getShippingRates')->once()->andReturn($shipment));

        $data = $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk()->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('standard', $data[0]['method']);
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

    private function mockTwoRates(string $suffix): void
    {
        $shipment = (object) ['id' => "shp_{$suffix}", 'rates' => [
            (object) ['id' => "rate_usps_{$suffix}", 'carrier' => 'USPS', 'service' => 'Ground Advantage', 'rate' => '6.00', 'currency' => 'USD', 'delivery_days' => 5],
            (object) ['id' => "rate_ups_{$suffix}", 'carrier' => 'UPS', 'service' => 'Next Day Air', 'rate' => '30.00', 'currency' => 'USD', 'delivery_days' => 1],
        ]];

        $this->mock(EasyPostServiceInterface::class, fn ($mock) => $mock->shouldReceive('getShippingRates')->once()->andReturn($shipment));
    }
}
