<?php

namespace Tests\Feature;

use App\Contracts\EasyPostServiceInterface;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\Services\ShippingQuoteService;
use App\Services\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

class VariantPricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_discounted_product_gives_its_variants_the_same_percentage_off(): void
    {
        $product = Product::factory()->create(['price' => 200, 'discount_price' => 20]);
        $same = ProductVariant::create(['product_id' => $product->id, 'name' => 'M', 'sku' => 'V-M', 'price' => 200, 'stock_qty' => 3]);
        $pricier = ProductVariant::create(['product_id' => $product->id, 'name' => 'XL', 'sku' => 'V-XL', 'price' => 250, 'stock_qty' => 3]);
        $inherits = ProductVariant::create(['product_id' => $product->id, 'name' => 'S', 'sku' => 'V-S', 'price' => null, 'stock_qty' => 3]);

        $this->assertSame(20.0, $same->finalPriceFor($product));
        $this->assertSame(25.0, $pricier->finalPriceFor($product));
        $this->assertSame(20.0, $inherits->finalPriceFor($product));
    }

    public function test_variants_without_a_product_discount_keep_their_price(): void
    {
        $product = Product::factory()->create(['price' => 100, 'discount_price' => null]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'M', 'sku' => 'V-M', 'price' => 120, 'stock_qty' => 3]);

        $this->assertSame(120.0, $variant->finalPriceFor($product));
    }

    public function test_public_product_responses_expose_the_variant_final_price(): void
    {
        $category = Category::create(['name' => 'Dresses', 'slug' => 'dresses', 'is_active' => true]);
        $product = Product::factory()->create(['price' => 200, 'discount_price' => 20, 'status' => 'published', 'in_stock' => true, 'stock_qty' => 5, 'category_id' => $category->id]);
        ProductVariant::create(['product_id' => $product->id, 'name' => 'M', 'sku' => 'V-M', 'price' => 200, 'stock_qty' => 3]);

        $this->getJson("/api/v1/products/{$product->slug}")
            ->assertOk()
            ->assertJsonPath('data.variants.0.price', 200)
            ->assertJsonPath('data.variants.0.final_price', 20);
    }

    public function test_checkout_charges_the_discounted_variant_price(): void
    {
        Mail::fake();
        Queue::fake();
        $this->mock(StripeCheckoutService::class, function ($mock): void {
            $mock->shouldReceive('createCheckoutSession')->andReturn(
                StripeSession::constructFrom(['id' => 'cs_variant', 'url' => 'https://checkout.stripe.com/cs_variant'])
            );
        });
        $product = Product::factory()->create(['price' => 200, 'discount_price' => 20, 'stock_qty' => 5, 'in_stock' => true]);
        $variant = ProductVariant::create(['product_id' => $product->id, 'name' => 'M', 'sku' => 'V-M', 'price' => 200, 'stock_qty' => 3]);
        $items = [['product_id' => $product->id, 'variant_id' => $variant->id, 'quantity' => 2]];
        $address = ['name' => 'Buyer', 'line1' => '1 Main St', 'city' => 'Los Angeles', 'state' => 'CA', 'postal_code' => '90001', 'country' => 'US'];
        $rate = (object) ['id' => 'rate_v1', 'shipment_id' => 'shp_v1', 'carrier' => 'USPS', 'service' => 'Priority', 'rate' => '0.00', 'currency' => 'USD', 'delivery_days' => 3];
        $easyPost = $this->mock(EasyPostServiceInterface::class);
        $easyPost->shouldReceive('getShippingRates')->once()->andReturn((object) ['id' => 'shp_v1', 'rates' => [$rate]]);
        $easyPost->shouldReceive('retrieveRate')->zeroOrMoreTimes()->andReturn($rate);
        app(ShippingQuoteService::class)->quote($address, $items);
        $quote = ['address' => $address, 'rateId' => 'rate_v1'];

        $this->actingAs(User::factory()->create(), 'sanctum')->postJson('/api/v1/orders', [
            'items' => $items, 'shipping_address' => $quote['address'], 'shipping_rate_id' => $quote['rateId'],
        ])->assertCreated();

        $order = Order::firstOrFail();
        $this->assertSame(20.0, (float) $order->items()->first()->price);
        $this->assertSame(40.0, (float) $order->subtotal);
    }

    public function test_admin_product_responses_include_category_id_and_it_is_saved_on_create(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));
        $category = Category::create(['name' => 'Women', 'slug' => 'women', 'is_active' => true]);

        $id = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/products', [
            'name' => 'Linen Dress', 'price' => 80, 'stock_qty' => 4, 'in_stock' => true,
            'status' => 'published', 'category_id' => $category->id,
            'weight_oz' => 8, 'length_in' => 8, 'width_in' => 6, 'height_in' => 2,
        ])->assertCreated()->assertJsonPath('data.category_id', $category->id)->json('data.id');

        $this->actingAs($admin, 'sanctum')->getJson("/api/v1/admin/products/{$id}")
            ->assertOk()->assertJsonPath('data.category_id', $category->id);
        $this->getJson('/api/v1/categories/women')->assertOk();
        $this->getJson("/api/v1/products?category={$category->id}")
            ->assertOk()->assertJsonFragment(['id' => $id]);
    }
}
