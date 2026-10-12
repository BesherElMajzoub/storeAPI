<?php

namespace Tests\Feature;

use App\Contracts\EasyPostServiceInterface;
use App\Models\Order;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TrackShipmentsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_hand_shipped_orders_are_not_polled(): void
    {
        Order::factory()->for(User::factory())->create([
            'status' => 'shipped',
            'shipment_source' => 'manual',
            'easypost_shipment_id' => 'shp_manual',
            'tracking_number' => 'D100',
        ]);

        $this->mock(EasyPostServiceInterface::class, fn ($mock) => $mock->shouldReceive('retrieveShipment')->never());

        $this->artisan('shipping:track')->assertSuccessful();
    }

    public function test_a_shipment_easypost_cannot_find_does_not_stop_the_run(): void
    {
        Order::factory()->for(User::factory())->create([
            'status' => 'shipped',
            'shipment_source' => 'easypost',
            'easypost_shipment_id' => 'shp_gone',
            'tracking_number' => '9400',
        ]);

        $this->mock(EasyPostServiceInterface::class, fn ($mock) => $mock->shouldReceive('retrieveShipment')
            ->once()
            ->andThrow(new Exception('Failed to retrieve shipment: The requested resource could not be found.')));

        $this->artisan('shipping:track')->assertSuccessful();
    }
}
