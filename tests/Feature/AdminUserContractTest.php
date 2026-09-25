<?php

namespace Tests\Feature;

use App\Models\Address;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_users_searches_name_email_and_phone(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        User::factory()->create([
            'name' => 'Needle Customer',
            'email' => 'first@example.test',
            'phone' => '+12025550101',
        ]);
        User::factory()->create([
            'name' => 'Second Customer',
            'email' => 'needle@example.test',
            'phone' => '+12025550102',
        ]);
        User::factory()->create([
            'name' => 'Third Customer',
            'email' => 'third@example.test',
            'phone' => '+1999NEEDLE',
        ]);
        User::factory()->create([
            'name' => 'Unrelated',
            'email' => 'unrelated@example.test',
            'phone' => '+12025550999',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=needle&per_page=100')
            ->assertOk();

        $this->assertCount(3, $response->json('data.data'));
    }

    public function test_admin_user_endpoints_use_explicit_safe_resources(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $user = User::factory()->create([
            'name' => 'Resource Customer',
            'email' => 'resource@example.test',
            'phone' => '+12025550199',
            'is_active' => true,
        ]);
        $address = Address::create([
            'user_id' => $user->id,
            'type' => 'shipping',
            'name' => 'Legacy Name',
            'line1' => 'Legacy Street',
            'label' => 'home',
            'full_name' => 'Resource Customer',
            'phone' => '+12025550199',
            'country' => 'US',
            'city' => 'New York',
            'street' => 'Main Street',
            'postal_code' => '10001',
            'is_default' => true,
        ]);
        $product = Product::factory()->create(['price' => 50, 'discount_price' => 40]);
        $user->wishlistItems()->create(['product_id' => $product->id]);

        $list = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/users?search=resource%40example.test')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $user->id)
            ->assertJsonPath('data.data.0.orders_count', 0)
            ->assertJsonPath('data.data.0.reviews_count', 0)
            ->assertJsonMissingPath('data.data.0.password')
            ->assertJsonMissingPath('data.data.0.remember_token');

        $this->assertSame(1, $list->json('data.total'));

        $this->getJson("/api/v1/admin/users/{$user->id}")
            ->assertOk()
            ->assertJsonPath('data.email', 'resource@example.test')
            ->assertJsonPath('data.orders_count', 0)
            ->assertJsonMissingPath('data.password');

        $this->getJson("/api/v1/admin/users/{$user->id}/wishlist")
            ->assertOk()
            ->assertJsonPath('data.wishlist_count', 1)
            ->assertJsonPath('data.wishlist.0.id', $product->id)
            ->assertJsonPath('data.wishlist.0.final_price', 40)
            ->assertJsonMissingPath('data.wishlist.0.stock_qty');

        $this->getJson("/api/v1/admin/users/{$user->id}/addresses")
            ->assertOk()
            ->assertJsonPath('data.addresses.0.id', $address->id)
            ->assertJsonPath('data.addresses.0.full_name', 'Resource Customer')
            ->assertJsonMissingPath('data.addresses.0.user_id')
            ->assertJsonMissingPath('data.addresses.0.line1');
    }
}
