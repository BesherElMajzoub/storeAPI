<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_change_password_when_logged_in(): void
    {
        $user = User::factory()->create([
            'password' => 'old_password_123',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'old_password_123',
            'new_password' => 'new_password_abc1',
            'new_password_confirmation' => 'new_password_abc1',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Password changed successfully.',
            ]);

        $this->assertTrue(Hash::check('new_password_abc1', $user->fresh()->password));
    }

    public function test_user_cannot_change_password_with_incorrect_current_password(): void
    {
        $user = User::factory()->create([
            'password' => 'old_password_123',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'wrong_password_123',
            'new_password' => 'new_password_abc1',
            'new_password_confirmation' => 'new_password_abc1',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'success' => false,
                'message' => 'The current password you entered is incorrect.',
            ]);

        $this->assertTrue(Hash::check('old_password_123', $user->fresh()->password));
    }

    public function test_user_cannot_change_password_unauthenticated(): void
    {
        $response = $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'old_password_123',
            'new_password' => 'new_password_abc1',
            'new_password_confirmation' => 'new_password_abc1',
        ]);

        $response->assertStatus(401);
    }

    public function test_changing_email_via_profile_update_resets_verification(): void
    {
        $user = User::factory()->create(['email' => 'old@example.com']);
        $this->assertNotNull($user->email_verified_at);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/me', [
            'email' => 'new@example.com',
        ]);

        $response->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertSame('new@example.com', $fresh->email);
        $this->assertNull($fresh->email_verified_at);
    }

    public function test_updating_unrelated_profile_field_keeps_verification(): void
    {
        $user = User::factory()->create(['email' => 'same@example.com']);
        $this->assertNotNull($user->email_verified_at);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/me', [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200);

        $fresh = $user->fresh();
        $this->assertSame('New Name', $fresh->name);
        $this->assertNotNull($fresh->email_verified_at);
    }

    public function test_profile_update_cannot_change_password_without_current_password_check(): void
    {
        $user = User::factory()->create(['password' => 'old_password_123']);

        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/auth/me', [
            'password' => 'new_password_abc1',
            'password_confirmation' => 'new_password_abc1',
        ]);

        $response->assertStatus(200);

        $this->assertTrue(Hash::check('old_password_123', $user->fresh()->password));
    }
}
