<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminContactMessageContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_filter_contact_messages_by_status_and_search_term(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::create(['name' => 'Admin']));
        $match = ContactMessage::create([
            'name' => 'Needle Customer',
            'email' => 'needle@example.test',
            'phone' => '+12025550111',
            'subject' => 'Shipping question',
            'message' => 'Where is my order?',
            'status' => 'new',
        ]);
        ContactMessage::create([
            'name' => 'Needle Archived',
            'email' => 'archived@example.test',
            'message' => 'Old message',
            'status' => 'archived',
        ]);
        ContactMessage::create([
            'name' => 'Unrelated',
            'email' => 'unrelated@example.test',
            'message' => 'Nothing to match',
            'status' => 'new',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/v1/admin/contact-messages?status=new&search=needle&limit=100')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $match->id);

        $this->assertStringContainsString('status=new', $response->json('data.first_page_url'));
        $this->assertStringContainsString('search=needle', $response->json('data.first_page_url'));

        $this->getJson('/api/v1/admin/contact-messages?status=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }
}
