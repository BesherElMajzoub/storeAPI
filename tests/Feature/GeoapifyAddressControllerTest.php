<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class GeoapifyAddressControllerTest extends TestCase
{
    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->session = Str::uuid()->toString();
        config(['services.location_provider' => 'geoapify']);
        config(['services.geoapify.api_key' => 'test_geoapify_key']);
    }

    public function test_geoapify_autocomplete_success()
    {
        Http::fake([
            'api.geoapify.com/v1/geocode/autocomplete*' => Http::response([
                'features' => [
                    [
                        'properties' => [
                            'place_id' => 'geo_xyz123',
                            'formatted' => '123 Main St, Anytown, CA, USA',
                            'address_line1' => '123 Main St',
                            'address_line2' => 'Anytown, CA, USA',
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->getJson("/api/v1/address/autocomplete?q=123+Main&session={$this->session}");

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.0.place_id', 'geo_xyz123')
                 ->assertJsonPath('data.0.main_text', '123 Main St');
    }

    public function test_geoapify_details_success()
    {
        Http::fake([
            'api.geoapify.com/v2/place-details*' => Http::response([
                'features' => [
                    [
                        'properties' => [
                            'housenumber' => '123',
                            'street' => 'Main St',
                            'city' => 'Anytown',
                            'state' => 'California',
                            'state_code' => 'CA',
                            'postcode' => '12345',
                            'country_code' => 'us',
                            'formatted' => '123 Main St, Anytown, CA 12345, USA',
                            'lat' => 37.7749,
                            'lon' => -122.4194,
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->getJson("/api/v1/address/details?place_id=geo_xyz123&session={$this->session}");

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.line1', '123 Main St')
                 ->assertJsonPath('data.city', 'Anytown')
                 ->assertJsonPath('data.state', 'CA')
                 ->assertJsonPath('data.postal_code', '12345')
                 ->assertJsonPath('data.country', 'US')
                 ->assertJsonPath('data.lat', 37.7749)
                 ->assertJsonPath('data.lng', -122.4194);
    }
}
