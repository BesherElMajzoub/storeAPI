<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CleanLegacyShipmentAndAddressDataCommandTest extends TestCase
{
    use RefreshDatabase;

    private function legacyData(): array
    {
        $user = User::factory()->create();

        $manual = Order::factory()->create([
            'user_id' => $user->id,
            'shipment_source' => 'manual',
            'tracking_number' => 'TRK1',
            'shipment_status' => 'pre_transit',
            'shipping_service' => 'GroundAdvantage',
        ]);
        $easypost = Order::factory()->create([
            'user_id' => $user->id,
            'shipment_source' => 'easypost',
            'tracking_number' => 'TRK2',
            'shipment_status' => 'pre_transit',
            'shipping_service' => 'GroundAdvantage',
        ]);

        $duplicated = Address::create(['user_id' => $user->id, 'name' => 'Buyer', 'phone' => '+15555550100', 'line1' => 'x', 'street' => '179 N HARBOR DR APT 2', 'apartment' => '2', 'floor' => '3', 'city' => 'Redondo Beach', 'state' => 'CA', 'postal_code' => '90277', 'country' => 'US']);
        $clean = Address::create(['user_id' => $user->id, 'name' => 'Buyer', 'phone' => '+15555550100', 'line1' => 'x', 'street' => '10 Main St', 'apartment' => '5', 'city' => 'Pasadena', 'state' => 'CA', 'postal_code' => '91101', 'country' => 'US']);

        return compact('manual', 'easypost', 'duplicated', 'clean');
    }

    public function test_preview_does_not_change_anything(): void
    {
        $d = $this->legacyData();

        $this->artisan('data:clean-legacy-shipments-addresses')->assertSuccessful();

        $this->assertSame('pre_transit', $d['manual']->fresh()->shipment_status);
        $this->assertSame('2', $d['duplicated']->fresh()->apartment);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_apply_resets_manual_orders_and_clears_duplicated_units_only(): void
    {
        $d = $this->legacyData();

        $this->artisan('data:clean-legacy-shipments-addresses', ['--apply' => true])->assertSuccessful();

        $manual = $d['manual']->fresh();
        $this->assertSame('unknown', $manual->shipment_status);
        $this->assertNull($manual->shipping_service);
        $this->assertSame('TRK1', $manual->tracking_number);

        $easypost = $d['easypost']->fresh();
        $this->assertSame('pre_transit', $easypost->shipment_status);
        $this->assertSame('GroundAdvantage', $easypost->shipping_service);

        $duplicated = $d['duplicated']->fresh();
        $this->assertNull($duplicated->apartment);
        $this->assertSame('3', $duplicated->floor);
        $this->assertSame('179 N HARBOR DR APT 2', $duplicated->street);
        $this->assertSame('5', $d['clean']->fresh()->apartment);

        $this->assertSame(1, AuditLog::where('action', 'cleaned_legacy_shipments_and_addresses')->count());
    }

    public function test_second_run_has_nothing_to_do(): void
    {
        $this->legacyData();
        $this->artisan('data:clean-legacy-shipments-addresses', ['--apply' => true])->assertSuccessful();

        $this->artisan('data:clean-legacy-shipments-addresses', ['--apply' => true])
            ->expectsOutputToContain('No legacy shipment or address data needs cleaning.')
            ->assertSuccessful();
    }
}
