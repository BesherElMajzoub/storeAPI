<?php

namespace Tests\Feature;

use App\Mail\ContactMessageReplyMail;
use App\Models\ContactMessage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactMessageRepliesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_reply_is_saved_emailed_and_marks_the_message_replied(): void
    {
        Mail::fake();
        $admin = $this->admin('Sara Admin');
        $message = $this->message('buyer@example.test');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/contact-messages/{$message->id}/replies", ['body' => 'It ships tomorrow.'])
            ->assertCreated()
            ->assertJsonPath('data.admin_name', 'Sara Admin')
            ->assertJsonPath('data.body', 'It ships tomorrow.')
            ->assertJsonStructure(['data' => ['id', 'admin_name', 'body', 'created_at']]);

        $this->assertSame('replied', $message->fresh()->status);
        Mail::assertQueued(ContactMessageReplyMail::class, fn ($mail) => $mail->hasTo('buyer@example.test'));
    }

    public function test_reply_needs_a_body_and_an_admin(): void
    {
        $message = $this->message('buyer@example.test');

        $this->actingAs($this->admin(), 'sanctum')
            ->postJson("/api/v1/admin/contact-messages/{$message->id}/replies", ['body' => ''])
            ->assertStatus(422);
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->postJson("/api/v1/admin/contact-messages/{$message->id}/replies", ['body' => 'Hi'])
            ->assertForbidden();
    }

    public function test_customer_sees_only_their_own_messages_with_replies(): void
    {
        Mail::fake();
        $customer = User::factory()->create(['email' => 'buyer@example.test']);
        $mine = $this->message('Buyer@Example.test', 'My order');
        $this->message('someone@else.test', 'Not mine');
        $this->actingAs($this->admin('Sara Admin'), 'sanctum')
            ->postJson("/api/v1/admin/contact-messages/{$mine->id}/replies", ['body' => 'On its way.'])->assertCreated();

        $response = $this->actingAs($customer, 'sanctum')->getJson('/api/v1/me/contact-messages')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $response->assertJsonPath('data.0.subject', 'My order')
            ->assertJsonPath('data.0.status', 'replied')
            ->assertJsonPath('data.0.replies.0.admin_name', 'Sara Admin')
            ->assertJsonPath('data.0.replies.0.body', 'On its way.')
            ->assertJsonStructure(['data' => [['id', 'subject', 'message', 'status', 'replies', 'created_at', 'updated_at']]]);
    }

    public function test_my_messages_requires_sign_in(): void
    {
        $this->getJson('/api/v1/me/contact-messages')->assertUnauthorized();
    }

    private function message(string $email, string $subject = 'Question'): ContactMessage
    {
        return ContactMessage::create(['name' => 'Buyer', 'email' => $email, 'subject' => $subject, 'message' => 'Where is it?', 'status' => 'new']);
    }

    private function admin(string $name = 'Admin'): User
    {
        $admin = User::factory()->create(['name' => $name]);
        $admin->roles()->attach(Role::firstOrCreate(['name' => 'Admin']));

        return $admin;
    }
}
