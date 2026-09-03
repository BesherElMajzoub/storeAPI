<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\ShippingRateQuote;
use App\Models\User;
use App\Services\EasyPostService;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class ShippingContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_package_is_selected_from_server_side_product_data(): void
    {
        $product = Product::factory()->create([
            'weight_oz' => 8, 'length_in' => 8, 'width_in' => 6, 'height_in' => 2,
        ]);
        $rate = (object) [
            'id' => 'rate_package', 'carrier' => 'USPS', 'service' => 'Priority',
            'rate' => '8.45', 'currency' => 'USD', 'delivery_days' => 3,
        ];
        $shipment = (object) ['id' => 'shp_package', 'rates' => [$rate]];
        $this->mock(EasyPostService::class, function ($mock) use ($shipment) {
            $mock->shouldReceive('getShippingRates')->once()->withArgs(function ($address, $parcel) {
                return $address['street1'] === '123 Main St'
                    && $parcel === ['length' => 10.0, 'width' => 8.0, 'height' => 4.0, 'weight' => 16.0];
            })->andReturn($shipment);
        });

        $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
        ])->assertOk()
            ->assertJsonPath('data.0.rate_id', 'rate_package')
            ->assertJsonPath('data.0.amount', 8.45);

        $this->assertDatabaseHas('shipping_rate_quotes', [
            'rate_id' => 'rate_package', 'shipment_id' => 'shp_package', 'amount' => 8.45,
        ]);
    }

    public function test_missing_dimensions_and_unsupported_destination_fail_closed(): void
    {
        $product = Product::factory()->create(['weight_oz' => null]);

        $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonPath('errors.code', 'shipping_configuration');

        $address = $this->address();
        $address['country'] = 'CA';
        $this->postJson('/api/v1/shipping/rates', [
            'address' => $address,
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertUnprocessable()->assertJsonPath('errors.code', 'unsupported_destination');
    }

    public function test_provider_outage_returns_503_instead_of_an_empty_rate_list(): void
    {
        $product = Product::factory()->create();
        $this->mock(EasyPostService::class, fn ($mock) => $mock->shouldReceive('getShippingRates')->once()->andThrow(new \Exception('timeout')));

        $this->postJson('/api/v1/shipping/rates', [
            'address' => $this->address(),
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
        ])->assertStatus(503)->assertJsonPath('data', null);
    }

    public function test_checkout_reprices_the_quote_and_rejects_expired_or_changed_quotes(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['price' => 20, 'stock_qty' => 5]);
        $shipping = $this->createShippingQuote($product, $this->address(), amount: 8.45);
        $session = StripeSession::constructFrom(['id' => 'cs_shipping', 'url' => 'https://checkout.stripe.test/cs_shipping']);
        $this->mock(StripeCheckoutService::class, fn ($mock) => $mock->shouldReceive('createCheckoutSession')->once()->andReturn($session));

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => $shipping['items'],
            'shipping_address' => $shipping['address'],
            'shipping_rate_id' => $shipping['rateId'],
        ])->assertCreated()
            ->assertJsonPath('data.order.shipping_cost', 8.45)
            ->assertJsonPath('data.order.total', 28.45);

        $this->assertDatabaseHas('shipping_rate_quotes', [
            'rate_id' => $shipping['rateId'], 'order_id' => Order::latest('id')->value('id'),
        ]);

        $expired = $this->createShippingQuote($product, $this->address());
        ShippingRateQuote::where('rate_id', $expired['rateId'])->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/api/v1/orders', [
            'items' => $expired['items'],
            'shipping_address' => $expired['address'],
            'shipping_rate_id' => $expired['rateId'],
        ])->assertUnprocessable();

        $changed = $this->createShippingQuote($product, $this->address());
        $changedAddress = $changed['address'];
        $changedAddress['line1'] = '999 Changed Street';
        $this->postJson('/api/v1/orders', [
            'items' => $changed['items'],
            'shipping_address' => $changedAddress,
            'shipping_rate_id' => $changed['rateId'],
        ])->assertUnprocessable()->assertJsonPath('errors.code', 'shipping_address_changed');
    }

    public function test_checkout_without_a_rate_is_rejected(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'shipping_address' => $this->address(),
        ])->assertUnprocessable()->assertJsonValidationErrors('shipping_rate_id');
    }

    public function test_legacy_parcel_contract_remains_available_and_is_marked_deprecated(): void
    {
        $rate = (object) [
            'id' => 'rate_legacy', 'carrier' => 'USPS', 'service' => 'Ground',
            'rate' => '6.00', 'currency' => 'USD', 'delivery_days' => 5, 'delivery_date' => null,
        ];
        $shipment = (object) ['id' => 'shp_legacy', 'rates' => [$rate]];
        $this->mock(EasyPostService::class, fn ($mock) => $mock->shouldReceive('getShippingRates')->once()->andReturn($shipment));

        $this->postJson('/api/v1/shipping/rates', [
            'address' => [
                'name' => 'Legacy', 'street1' => '123 Main St', 'city' => 'Pasadena',
                'state' => 'CA', 'zip' => '91101', 'country' => 'US',
            ],
            'parcel' => ['length' => 10, 'width' => 8, 'height' => 4, 'weight' => 16],
        ])->assertOk()
            ->assertHeader('Deprecation', 'true')
            ->assertJsonPath('data.shipment_id', 'shp_legacy')
            ->assertJsonPath('data.rates.0.id', 'rate_legacy');
    }

    public function test_label_endpoint_is_idempotent_and_requires_a_paid_processing_order(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $order = Order::factory()->create([
            'status' => 'shipped', 'payment_status' => 'paid',
            'tracking_number' => '940000000000', 'label_url' => 'https://labels.example/one.pdf',
        ]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/label")
            ->assertOk()->assertJsonPath('data.shipment.label_url', 'https://labels.example/one.pdf');

        $ineligible = Order::factory()->create(['status' => 'processing', 'payment_status' => 'unpaid']);
        $this->postJson("/api/v1/admin/orders/{$ineligible->id}/label")
            ->assertStatus(409);
    }

    private function address(): array
    {
        return [
            'name' => 'Buyer', 'line1' => '123 Main St', 'city' => 'Pasadena', 'state' => 'CA',
            'postal_code' => '91101', 'country' => 'US',
        ];
    }
}
