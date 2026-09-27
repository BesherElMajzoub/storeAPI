<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateStaffAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Tester-Pass-2026';

    protected function setUp(): void
    {
        parent::setUp();
        Role::create(['name' => 'Admin']);
    }

    public function test_creates_verified_active_admin_that_can_reach_admin_routes(): void
    {
        $this->artisan('app:create-admin', ['email' => 'QA.Tester@Example.com', 'name' => 'QA Tester'])
            ->expectsQuestion('Password (min 12 chars, upper + lower case, a number)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertSuccessful();

        $user = User::where('email', 'qa.tester@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('Admin'));
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));

        $audit = AuditLog::where('action', 'created_staff_account')->firstOrFail();
        $this->assertEquals(['user_id' => $user->id, 'role' => 'Admin'], $audit->changes);
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($audit->toArray()));

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertOk();
    }

    public function test_refuses_existing_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('app:create-admin', ['email' => 'taken@example.com', 'name' => 'Dup'])
            ->assertFailed();

        $this->assertSame(1, User::where('email', 'taken@example.com')->count());
    }

    public function test_refuses_non_staff_or_missing_role(): void
    {
        $this->artisan('app:create-admin', ['email' => 'a@example.com', 'name' => 'A', '--role' => 'User'])
            ->assertFailed();

        $this->artisan('app:create-admin', ['email' => 'b@example.com', 'name' => 'B', '--role' => 'Owner'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_refuses_weak_or_mismatched_password(): void
    {
        $this->artisan('app:create-admin', ['email' => 'c@example.com', 'name' => 'C'])
            ->expectsQuestion('Password (min 12 chars, upper + lower case, a number)', 'short1A')
            ->expectsQuestion('Confirm password', 'short1A')
            ->assertFailed();

        $this->artisan('app:create-admin', ['email' => 'c@example.com', 'name' => 'C'])
            ->expectsQuestion('Password (min 12 chars, upper + lower case, a number)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD.'x')
            ->expectsOutputToContain('do not match')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
