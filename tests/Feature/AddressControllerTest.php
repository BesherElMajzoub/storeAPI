<?php

namespace Tests\Feature;

use App\Contracts\EasyPostServiceInterface;
use App\Models\Address;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class AddressControllerTest extends TestCase
{
    use RefreshDatabase;

    private string $session;

    protected function setUp(): void
    {
        parent::setUp();

        $this->session = Str::uuid()->toString();
        config(['services.google.places_api_key' => 'test_api_key']);

        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('verifyAddress')
                ->byDefault()
                ->andReturnUsing(fn (array $address): array => $address);
        });
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
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $response = $this->getJson("/api/v1/address/autocomplete?q=123+Main&session={$this->session}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.place_id', 'ChIJxyz123')
            ->assertJsonPath('data.0.main_text', '123 Main St');
    }

    public function test_autocomplete_validation_error()
    {
        $response = $this->getJson('/api/v1/address/autocomplete?q=a'); // Less than 2 chars, no session

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
                ],
            ], 200),
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
        $response = $this->getJson('/api/v1/address/details'); // Missing place_id and session

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['place_id', 'session']);
    }

    public function test_google_api_failure()
    {
        Http::fake([
            'places.googleapis.com/v1/places:autocomplete' => Http::response(['error' => 'Bad request'], 400),
        ]);

        $response = $this->getJson("/api/v1/address/autocomplete?q=123+Main&session={$this->session}");

        $response->assertStatus(504)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A connection timeout or unexpected error occurred.');
    }

    public function test_user_can_list_addresses()
    {
        $user = User::factory()->create();
        Address::create([
            'user_id' => $user->id,
            'label' => 'home',
            'full_name' => 'John Doe',
            'phone' => '123456789',
            'country' => 'US',
            'city' => 'New York',
            'street' => '123 Main St',
            'name' => 'John Doe',
            'type' => 'shipping',
            'line1' => '123 Main St',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/profile/addresses');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['id', 'label', 'full_name']]]);
    }

    public function test_user_can_create_address()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/addresses', [
                'label' => 'work',
                'full_name' => 'Jane Smith',
                'phone' => '123456789',
                'country' => 'US',
                'city' => 'Boston',
                'street' => '456 Elm St',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.full_name', 'Jane Smith')
            ->assertJsonPath('data.is_default', true); // Automatically default if first address
    }

    public function test_address_is_verified_and_normalized_before_it_is_saved(): void
    {
        $user = User::factory()->create();

        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('verifyAddress')
                ->once()
                ->withArgs(fn (array $address): bool => $address['street1'] === '179 N Harbor Dr'
                    && $address['state'] === 'ca'
                    && $address['zip'] === '90277')
                ->andReturn([
                    'name' => 'Jane Smith',
                    'street1' => '179 N HARBOR DR',
                    'street2' => null,
                    'city' => 'REDONDO BEACH',
                    'state' => 'CA',
                    'zip' => '90277-2510',
                    'country' => 'US',
                    'phone' => '123456789',
                ]);
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/addresses', [
                'label' => 'home',
                'full_name' => 'Jane Smith',
                'phone' => '123456789',
                'country' => 'US',
                'city' => 'Redondo Beach',
                'state' => 'ca',
                'street' => '179 N Harbor Dr',
                'postal_code' => '90277',
            ])
            ->assertCreated()
            ->assertJsonPath('data.street', '179 N HARBOR DR')
            ->assertJsonPath('data.state', 'CA')
            ->assertJsonPath('data.postal_code', '90277-2510');

        $this->assertDatabaseHas('addresses', [
            'user_id' => $user->id,
            'line1' => '179 N HARBOR DR',
            'street' => '179 N HARBOR DR',
            'state' => 'CA',
            'postal_code' => '90277-2510',
        ]);
    }

    public function test_unverified_address_is_not_saved(): void
    {
        $user = User::factory()->create();

        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('verifyAddress')
                ->once()
                ->andThrow(new \Exception('Street could not be found.'));
        });

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/profile/addresses', [
                'label' => 'home',
                'full_name' => 'Jane Smith',
                'phone' => '123456789',
                'country' => 'US',
                'city' => 'Nowhere',
                'street' => 'Not a real street',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Address verification failed.')
            ->assertJsonPath('errors.address.0', 'Street could not be found.');

        $this->assertDatabaseCount('addresses', 0);
    }

    public function test_user_can_update_address()
    {
        $user = User::factory()->create();
        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'home',
            'full_name' => 'Old Name',
            'phone' => '123456789',
            'country' => 'US',
            'city' => 'New York',
            'street' => '123 Main St',
            'name' => 'Old Name',
            'type' => 'shipping',
            'line1' => '123 Main St',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/profile/addresses/{$address->id}", [
                'full_name' => 'New Name',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.full_name', 'New Name');
    }

    public function test_failed_verification_does_not_modify_an_existing_address(): void
    {
        $user = User::factory()->create();
        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'home',
            'full_name' => 'Jane Smith',
            'phone' => '123456789',
            'country' => 'US',
            'city' => 'New York',
            'street' => '123 Main St',
            'name' => 'Jane Smith',
            'type' => 'shipping',
            'line1' => '123 Main St',
        ]);

        $this->mock(EasyPostServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('verifyAddress')
                ->once()
                ->andThrow(new \Exception('City and postal code do not match.'));
        });

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/profile/addresses/{$address->id}", ['city' => 'Boston'])
            ->assertUnprocessable();

        $this->assertSame('New York', $address->fresh()->city);
    }

    public function test_user_can_delete_address()
    {
        $user = User::factory()->create();
        $address = Address::create([
            'user_id' => $user->id,
            'label' => 'home',
            'full_name' => 'Old Name',
            'phone' => '123456789',
            'country' => 'US',
            'city' => 'New York',
            'street' => '123 Main St',
            'name' => 'Old Name',
            'type' => 'shipping',
            'line1' => '123 Main St',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/v1/profile/addresses/{$address->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('addresses', ['id' => $address->id]);
    }

    public function test_user_can_set_default_address()
    {
        $user = User::factory()->create();
        $address1 = Address::create([
            'user_id' => $user->id,
            'label' => 'home',
            'full_name' => 'Old Name',
            'phone' => '123456789',
            'country' => 'US',
            'city' => 'New York',
            'street' => '123 Main St',
            'is_default' => true,
            'name' => 'Old Name',
            'type' => 'shipping',
            'line1' => '123 Main St',
        ]);
        $address2 = Address::create([
            'user_id' => $user->id,
            'label' => 'work',
            'full_name' => 'Old Name',
            'phone' => '123456789',
            'country' => 'US',
            'city' => 'Boston',
            'street' => '456 Elm St',
            'is_default' => false,
            'name' => 'Old Name',
            'type' => 'shipping',
            'line1' => '456 Elm St',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/profile/addresses/{$address2->id}/default");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue($address2->fresh()->is_default);
        $this->assertFalse($address1->fresh()->is_default);
    }
}
