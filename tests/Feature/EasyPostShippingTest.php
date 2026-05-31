<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\EasyPostService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class EasyPostShippingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 50.00,
            'in_stock' => true,
            'status' => 'published',
        ]);

        // Grant admin role
        $role = \App\Models\Role::firstOrCreate(['name' => 'admin']);
        $this->admin->roles()->attach($role->id);
    }

    public function test_customer_can_verify_address(): void
    {
        $mockVerifiedAddress = [
            'name'    => 'JOHN DOE',
            'street1' => '179 N HARBOR DR',
            'street2' => null,
            'city'    => 'REDONDO BEACH',
            'state'   => 'CA',
            'zip'     => '90277-2510',
            'country' => 'US',
            'phone'   => '3108085243',
        ];

        $this->mock(EasyPostService::class, function ($mock) use ($mockVerifiedAddress) {
            $mock->shouldReceive('verifyAddress')->once()->andReturn($mockVerifiedAddress);
        });

        $response = $this->postJson('/api/v1/shipping/verify-address', [
            'name'    => 'John Doe',
            'street1' => '179 N Harbor Dr',
            'city'    => 'Redondo Beach',
            'state'   => 'CA',
            'zip'     => '90277',
            'country' => 'US',
            'phone'   => '310-808-5243'
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.city', 'REDONDO BEACH');
    }

    public function test_customer_can_get_shipping_rates(): void
    {
        // Mock EasyPost Shipment object
        $mockShipment = (object)[
            'id' => 'shp_test123',
            'rates' => [
                (object)[
                    'id'            => 'rate_test123',
                    'carrier'       => 'USPS',
                    'service'       => 'First',
                    'rate'          => '5.50',
                    'currency'      => 'USD',
                    'delivery_days' => 3,
                    'delivery_date' => null,
                ]
            ]
        ];

        $this->mock(EasyPostService::class, function ($mock) use ($mockShipment) {
            $mock->shouldReceive('getShippingRates')->once()->andReturn($mockShipment);
        });

        $response = $this->postJson('/api/v1/shipping/rates', [
            'address' => [
                'name'    => 'Jane Doe',
                'street1' => '179 N Harbor Dr',
                'city'    => 'Redondo Beach',
                'state'   => 'CA',
                'zip'     => '90277',
                'country' => 'US',
            ]
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.shipment_id', 'shp_test123')
            ->assertJsonPath('data.rates.0.rate', 5.50);
    }

    public function test_admin_can_purchase_label(): void
    {
        $order = Order::create([
            'order_number'         => 'ORD-TEST12345',
            'user_id'              => $this->user->id,
            'status'               => 'processing',
            'payment_status'       => 'paid',
            'subtotal'             => 100.00,
            'total'                => 105.50,
            'shipping_cost'        => 5.50,
            'easypost_shipment_id' => 'shp_test123',
            'shipping_address'     => ['name' => 'John', 'street' => '1 Main St', 'city' => 'NYC', 'country' => 'US'],
        ]);

        $mockBoughtShipment = (object)[
            'tracking_code' => 'EZ1000000001',
            'postage_label' => (object)[
                'label_url' => 'https://easypost.com/label.png'
            ]
        ];

        $this->mock(EasyPostService::class, function ($mock) use ($mockBoughtShipment) {
            $mock->shouldReceive('purchaseLabel')
                ->once()
                ->with('shp_test123', 'rate_test123')
                ->andReturn($mockBoughtShipment);
        });

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/ship", [
                'rate_id'     => 'rate_test123',
                'shipment_id' => 'shp_test123'
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.tracking_number', 'EZ1000000001')
            ->assertJsonPath('data.label_url', 'https://easypost.com/label.png');

        $this->assertDatabaseHas('orders', [
            'id'              => $order->id,
            'status'          => 'shipped',
            'tracking_number' => 'EZ1000000001',
            'label_url'       => 'https://easypost.com/label.png',
        ]);
    }

    public function test_easypost_webhook_updates_order(): void
    {
        $order = Order::create([
            'order_number'         => 'ORD-TEST12345',
            'user_id'              => $this->user->id,
            'status'               => 'shipped',
            'payment_status'       => 'paid',
            'subtotal'             => 100.00,
            'total'                => 105.50,
            'tracking_number'      => 'EZ1000000001',
            'easypost_shipment_id' => 'shp_test123',
            'shipping_address'     => ['name' => 'John', 'street' => '1 Main St', 'city' => 'NYC', 'country' => 'US'],
        ]);

        $mockEvent = (object)[
            'description' => 'tracker.updated',
            'result'      => (object)[
                'tracking_code' => 'EZ1000000001',
                'status'        => 'delivered',
            ]
        ];

        $this->mock(EasyPostService::class, function ($mock) use ($mockEvent) {
            $mock->shouldReceive('validateWebhook')->once()->andReturn($mockEvent);
        });

        // Set mock secret so webhook controller runs validation
        config(['services.easypost.webhook_secret' => 'whsec_test']);

        $response = $this->postJson('/api/v1/webhooks/easypost', [
            'description' => 'tracker.updated'
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => 'delivered',
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
