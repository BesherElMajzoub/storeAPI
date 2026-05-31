<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCouponTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Set up Admin User
        $this->admin = User::factory()->create();
        $adminRole = Role::firstOrCreate(['name' => 'Admin'], ['label' => 'Admin']);
        $this->admin->roles()->attach($adminRole);

        // Set up standard customer
        $this->customer = User::factory()->create();
    }

    // ── 1. Create Percentage Coupon ──────────────────────────────────────────

    public function test_admin_can_create_percentage_coupon(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/coupons', [
                'code'                    => 'welcome50', // tests lowercase autocasing
                'type'                    => 'percentage',
                'value'                   => 50.00,
                'minimum_order_amount'    => 10.00,
                'maximum_discount_amount' => 50.00,
                'is_active'               => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'WELCOME50') // Uppercase verify
            ->assertJsonPath('data.type', 'percentage')
            ->assertJsonPath('data.value', '50.00');

        $this->assertDatabaseHas('coupons', [
            'code' => 'WELCOME50',
            'type' => 'percentage',
        ]);
    }

    // ── 2. Create Fixed Coupon ───────────────────────────────────────────────

    public function test_admin_can_create_fixed_coupon(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/coupons', [
                'code'      => 'save20',
                'type'      => 'fixed',
                'value'     => 20.00,
                'is_active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'SAVE20')
            ->assertJsonPath('data.type', 'fixed')
            ->assertJsonPath('data.value', '20.00');

        $this->assertDatabaseHas('coupons', [
            'code'  => 'SAVE20',
            'type'  => 'fixed',
            'value' => 20.00,
        ]);
    }

    // ── 3. Duplicate Code Prevention ─────────────────────────────────────────

    public function test_admin_cannot_create_duplicate_code(): void
    {
        Coupon::create([
            'code'      => 'WELCOME50',
            'type'      => 'percentage',
            'value'     => 50.00,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/coupons', [
                'code'  => 'welcome50', // tests lowercase uppercase uniqueness
                'type'  => 'percentage',
                'value' => 50.00,
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ── 4. Percentage Limit Validation ───────────────────────────────────────

    public function test_percentage_value_cannot_exceed_100(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/coupons', [
                'code'  => 'FREEPASS',
                'type'  => 'percentage',
                'value' => 150.00, // Invalid > 100
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['value']]);
    }

    // ── 5. Update Coupon ─────────────────────────────────────────────────────

    public function test_admin_can_update_coupon(): void
    {
        $coupon = Coupon::create([
            'code'      => 'OLDCODE',
            'type'      => 'fixed',
            'value'     => 10.00,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/admin/coupons/{$coupon->id}", [
                'code'      => 'newcode',
                'type'      => 'fixed',
                'value'     => 15.00,
                'is_active' => true,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'NEWCODE')
            ->assertJsonPath('data.value', '15.00');

        $this->assertDatabaseHas('coupons', [
            'id'   => $coupon->id,
            'code' => 'NEWCODE',
        ]);
    }

    // ── 6. Toggle Status ─────────────────────────────────────────────────────

    public function test_admin_can_toggle_coupon_status(): void
    {
        $coupon = Coupon::create([
            'code'      => 'TOGGLEME',
            'type'      => 'fixed',
            'value'     => 10.00,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/coupons/{$coupon->id}/toggle-status");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_active', false);

        $this->assertFalse($coupon->fresh()->is_active);
    }

    // ── 7. List with Filters ─────────────────────────────────────────────────

    public function test_admin_can_list_coupons_with_filters(): void
    {
        // 1. Scheduled coupon
        Coupon::create([
            'code'      => 'FUTURE1',
            'type'      => 'fixed',
            'value'     => 10.00,
            'starts_at' => now()->addDays(5),
            'is_active' => true,
        ]);

        // 2. Expired coupon
        Coupon::create([
            'code'       => 'PAST1',
            'type'       => 'percentage',
            'value'      => 15.00,
            'expires_at' => now()->subDays(5),
            'is_active'  => true,
        ]);

        // 3. Active coupon
        Coupon::create([
            'code'      => 'ACTIVE1',
            'type'      => 'fixed',
            'value'     => 20.00,
            'is_active' => true,
        ]);

        // Test Filter Type
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/coupons?type=percentage');
        $response->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertCount(1, $response->json('data.data'));

        // Test Filter Status Scheduled
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/coupons?status=scheduled');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('FUTURE1', $response->json('data.data.0.code'));

        // Test Filter Status Expired
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/coupons?status=expired');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('PAST1', $response->json('data.data.0.code'));
    }

    // ── 8. View Coupon Usages ────────────────────────────────────────────────

    public function test_admin_can_view_coupon_usages(): void
    {
        $coupon = Coupon::create([
            'code'      => 'USAGETEST',
            'type'      => 'fixed',
            'value'     => 10.00,
            'is_active' => true,
        ]);

        $order = Order::create([
            'order_number'     => 'ORD-1001',
            'user_id'          => $this->customer->id,
            'status'           => 'delivered',
            'subtotal'         => 100.00,
            'total'            => 90.00,
            'shipping_address' => ['name' => 'John'],
        ]);

        CouponUsage::create([
            'coupon_id'       => $coupon->id,
            'user_id'         => $this->customer->id,
            'order_id'        => $order->id,
            'discount_amount' => 10.00,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/coupons/{$coupon->id}/usages");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'coupon_id',
                            'order_id',
                            'order_number',
                            'user' => ['id', 'name', 'email'],
                            'discount_amount',
                            'created_at',
                        ]
                    ]
                ]
            ]);
    }

    // ── 9. Delete Restriction ────────────────────────────────────────────────

    public function test_used_coupon_cannot_be_deleted_directly(): void
    {
        $coupon = Coupon::create([
            'code'       => 'CANNOTDELETE',
            'type'       => 'fixed',
            'value'      => 10.00,
            'used_count' => 1,
            'is_active'  => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/coupons/{$coupon->id}");

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['coupon']]);

        $this->assertDatabaseHas('coupons', ['id' => $coupon->id]);
    }
}
