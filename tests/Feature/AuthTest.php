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
}
