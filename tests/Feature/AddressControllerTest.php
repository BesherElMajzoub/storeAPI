<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AddressControllerTest extends TestCase
{
    private string $session;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->session = Str::uuid()->toString();
        config(['services.google.places_api_key' => 'test_api_key']);
    }

    public function test_autocomplete_success()
    {
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response([
                'suggestions' => [
                    [
                        'placePrediction' => [
                            'placeId' => 'ChIJxyz123',
                            'text' => ['text' => '123 Main St, Anytown, CA, USA'],
                            'structuredFormat' => [
                                'mainText' => ['text' => '123 Main St'],
                                'secondaryText' => ['text' => 'Anytown, CA, USA'],
                            ]
                        ]
                    ]
                ]
            ], 200)
        ]);

        $response = $this->getJson("/api/v1/address/autocomplete?q=123+Main&session={$this->session}");

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.0.place_id', 'ChIJxyz123')
                 ->assertJsonPath('data.0.main_text', '123 Main St');
    }

    public function test_autocomplete_validation_error()
    {
        $response = $this->getJson("/api/v1/address/autocomplete?q=a"); // Less than 2 chars, no session

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['q', 'session']);
    }

    public function test_details_success()
    {
        Http::fake([
            'places.googleapis.com/v1/places/ChIJxyz123*' => Http::response([
                'formattedAddress' => '123 Main St, Anytown, CA 12345, USA',
                'location' => ['latitude' => 37.7749, 'longitude' => -122.4194],
                'addressComponents' => [
                    ['types' => ['street_number'], 'longText' => '123'],
                    ['types' => ['route'], 'longText' => 'Main St'],
                    ['types' => ['locality'], 'longText' => 'Anytown'],
                    ['types' => ['administrative_area_level_1'], 'shortText' => 'CA'],
                    ['types' => ['postal_code'], 'longText' => '12345'],
                    ['types' => ['country'], 'shortText' => 'US'],
                ]
            ], 200)
        ]);

        $response = $this->getJson("/api/v1/address/details?place_id=ChIJxyz123&session={$this->session}");

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

    public function test_details_validation_error()
    {
        $response = $this->getJson("/api/v1/address/details"); // Missing place_id and session

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['place_id', 'session']);
    }

    public function test_google_api_failure()
    {
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response(['error' => 'Bad request'], 400)
        ]);

        $response = $this->getJson("/api/v1/address/autocomplete?q=123+Main&session={$this->session}");

        $response->assertStatus(504)
                 ->assertJsonPath('success', false)
                 ->assertJsonPath('message', 'A connection timeout or unexpected error occurred.');
    }
}
