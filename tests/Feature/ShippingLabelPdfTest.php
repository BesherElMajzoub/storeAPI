<?php

namespace Tests\Feature;

use App\Contracts\EasyPostServiceInterface;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Services\ShippingLabelStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShippingLabelPdfTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n4x6 label\n%%EOF";

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
    }

    public function test_a_purchased_label_is_stored_as_pdf_and_opens_from_a_signed_link(): void
    {
        Http::fake(['https://easypost.test/*' => Http::response(self::PDF, 200, ['Content-Type' => 'application/pdf'])]);
        $order = $this->paidOrder();
        $this->mockLabelPurchase(['label_url' => 'https://easypost.test/label.pdf', 'label_file_type' => 'application/pdf']);

        $link = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/label")
            ->assertOk()
            ->assertJsonPath('data.shipment.status', 'pre_transit')
            ->json('data.shipment.label_download_url');

        Storage::disk('local')->assertExists("labels/{$order->order_number}.pdf");
        $this->assertNotNull($link);

        // A phone opening the link sends no bearer token.
        $this->app['auth']->forgetGuards();
        $response = $this->get($link)->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('inline; filename=label-'.$order->order_number.'.pdf', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(self::PDF, $response->streamedContent());
    }

    public function test_the_link_keeps_working_after_the_easypost_url_expires(): void
    {
        Http::fake(['https://easypost.test/*' => Http::response(self::PDF)]);
        $order = $this->paidOrder();
        $this->mockLabelPurchase(['label_url' => 'https://easypost.test/label.pdf', 'label_file_type' => 'application/pdf']);
        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/label")->assertOk();

        Http::fake(['https://easypost.test/*' => Http::response('Expired', 403)]);
        $order->forceFill(['label_url' => null])->saveQuietly();

        $link = $this->actingAs($this->admin(), 'sanctum')
            ->getJson("/api/v1/admin/orders/{$order->id}")
            ->json('data.shipment.label_download_url');

        $this->app['auth']->forgetGuards();
        $this->assertSame(self::PDF, $this->get($link)->assertOk()->streamedContent());
    }

    public function test_a_png_label_is_converted_to_pdf(): void
    {
        Http::fake([
            'https://easypost.test/label.png' => Http::response('PNG'),
            'https://easypost.test/converted.pdf' => Http::response(self::PDF),
        ]);
        $order = $this->paidOrder();
        $this->mockLabelPurchase(
            ['label_url' => 'https://easypost.test/label.png', 'label_file_type' => 'image/png'],
            fn ($mock) => $mock->shouldReceive('pdfLabelUrl')->once()->with('shp_1')->andReturn('https://easypost.test/converted.pdf'),
        );

        $this->actingAs($this->admin(), 'sanctum')->postJson("/api/v1/admin/orders/{$order->id}/label")->assertOk();

        $this->assertSame(self::PDF, Storage::disk('local')->get("labels/{$order->order_number}.pdf"));
    }

    public function test_an_expired_or_forged_signature_is_refused(): void
    {
        Http::fake(['https://easypost.test/*' => Http::response(self::PDF)]);
        $order = $this->paidOrder();
        $this->mockLabelPurchase(['label_url' => 'https://easypost.test/label.pdf', 'label_file_type' => 'application/pdf']);
        $link = $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/orders/{$order->id}/label")
            ->json('data.shipment.label_download_url');
        $this->app['auth']->forgetGuards();

        $this->get("/api/v1/admin/shipments/{$order->id}/label")->assertForbidden();
        $this->get(str_replace("shipments/{$order->id}/", 'shipments/999/', $link))->assertForbidden();

        $this->travel(16)->minutes();
        $this->get($link)->assertForbidden();
    }

    public function test_the_fake_shipping_driver_stores_a_placeholder_4x6_pdf(): void
    {
        config(['services.easypost.driver' => 'fake']);
        Http::preventStrayRequests();
        $order = $this->paidOrder();
        $order->forceFill(['tracking_number' => 'MOCK123'])->saveQuietly();

        $path = app(ShippingLabelStore::class)->store($order);

        $pdf = Storage::disk('local')->get($path);
        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('/MediaBox [0 0 288 432]', $pdf);
        $this->assertStringContainsString("xref\n0 6\n", $pdf);
        $this->assertStringEndsWith("%%EOF\n", $pdf);
    }

    private function paidOrder(): Order
    {
        return Order::factory()->create([
            'status' => 'processing', 'payment_status' => 'paid',
            'easypost_shipment_id' => 'shp_1', 'shipping_rate_id' => 'rate_1',
        ]);
    }

    private function mockLabelPurchase(array $postageLabel, ?callable $extra = null): void
    {
        $this->mock(EasyPostServiceInterface::class, function ($mock) use ($postageLabel, $extra): void {
            $mock->shouldReceive('purchaseLabel')->once()->andReturn((object) [
                'tracking_code' => 'EZ100',
                'postage_label' => (object) $postageLabel,
                'tracker' => (object) ['status' => 'unknown', 'carrier' => 'USPS', 'tracking_details' => []],
            ]);
            if ($extra) {
                $extra($mock);
            }
        });
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));

        return $admin;
    }
}
