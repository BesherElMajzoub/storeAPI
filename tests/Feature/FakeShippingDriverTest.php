<?php

namespace Tests\Feature;

use App\Contracts\EasyPostServiceInterface;
use App\Models\Product;
use App\Services\FakeEasyPostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FakeShippingDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_driver_supports_the_staging_quote_label_tracking_and_webhook_contract(): void
    {
        config([
            'services.easypost.driver' => 'fake',
            'services.easypost.webhook_secret' => 'mock-webhook-secret',
        ]);
        $this->app->forgetInstance(EasyPostServiceInterface::class);

        $provider = app(EasyPostServiceInterface::class);
        $this->assertInstanceOf(FakeEasyPostService::class, $provider);

        $verified = $provider->verifyAddress([
            'name' => 'Staging Customer',
            'street1' => '1 Test Street',
            'city' => 'New York',
            'state' => 'ny',
            'zip' => '10001',
            'country' => 'us',
        ]);
        $this->assertSame('STAGING CUSTOMER', $verified['name']);
        $this->assertSame('US', $verified['country']);

        $shipment = $provider->getShippingRates($verified, [
            'length' => 10,
            'width' => 8,
            'height' => 4,
            'weight' => 16,
        ]);
        $this->assertCount(2, $shipment->rates);
        $this->assertSame('MockCarrier', $shipment->rates[0]->carrier);

        $rate = $provider->retrieveRate($shipment->rates[0]->id);
        $this->assertSame($shipment->id, $rate->shipment_id);

        $purchased = $provider->purchaseLabel($shipment->id, $rate->id);
        $this->assertStringStartsWith('MOCK', $purchased->tracking_code);
        $this->assertSame('pre_transit', $purchased->tracker->status);
        $this->assertSame($purchased->tracking_code, $provider->retrieveShipment($shipment->id)->tracker->tracking_code);
        $this->assertSame($purchased->tracking_code, $provider->getTrackingStatus($purchased->tracker->id)->tracking_code);

        $payload = json_encode([
            'description' => 'tracker.updated',
            'result' => ['tracking_code' => $purchased->tracking_code, 'status' => 'in_transit'],
        ], JSON_THROW_ON_ERROR);
        $event = $provider->validateWebhook($payload, [
            'x-mock-signature' => [hash_hmac('sha256', $payload, 'mock-webhook-secret')],
        ]);
        $this->assertSame('tracker.updated', $event->description);
        $this->assertSame('in_transit', $event->result->status);
    }

    public function test_item_based_rate_endpoint_can_run_entirely_on_the_fake_driver(): void
    {
        config(['services.easypost.driver' => 'fake']);
        $this->app->forgetInstance(EasyPostServiceInterface::class);
        $product = Product::factory()->create([
            'status' => 'published',
            'in_stock' => true,
            'stock_qty' => 5,
            'weight_oz' => 16,
            'length_in' => 8,
            'width_in' => 6,
            'height_in' => 3,
        ]);

        $this->postJson('/api/v1/shipping/rates', [
            'address' => [
                'line1' => '1 Test Street',
                'city' => 'New York',
                'state' => 'NY',
                'postal_code' => '10001',
                'country' => 'US',
            ],
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertOk()
            ->assertJsonPath('data.0.carrier', 'MockCarrier')
            ->assertJsonPath('data.0.service', 'Ground')
            ->assertJsonPath('data.0.amount', 7.95)
            ->assertJsonStructure(['data' => [['rate_id', 'expires_at']]]);
    }

    public function test_fake_or_unknown_shipping_driver_fails_closed_in_production(): void
    {
        $this->app['env'] = 'production';

        foreach (['fake', 'unknown'] as $driver) {
            config(['services.easypost.driver' => $driver]);
            $this->app->forgetInstance(EasyPostServiceInterface::class);

            try {
                app(EasyPostServiceInterface::class);
                $this->fail("Shipping driver {$driver} should not resolve in production.");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('shipping driver', strtolower($e->getMessage()));
            }
        }
    }
}
