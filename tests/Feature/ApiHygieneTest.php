<?php

namespace Tests\Feature;

use App\Jobs\SendAdminAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ApiHygieneTest extends TestCase
{
    use RefreshDatabase;

    public function test_cors_allows_the_storefront_and_rejects_an_unlisted_origin(): void
    {
        config(['cors.allowed_origins' => ['https://otantikqueen.com']]);

        $this->options('/api/v1/products', [], [
            'Origin' => 'https://otantikqueen.com',
            'Access-Control-Request-Method' => 'GET',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://otantikqueen.com');

        $unlisted = $this->options('/api/v1/products', [], [
            'Origin' => 'https://attacker.example',
            'Access-Control-Request-Method' => 'GET',
        ]);

        $this->assertNotSame(
            'https://attacker.example',
            $unlisted->headers->get('Access-Control-Allow-Origin')
        );
        $this->assertNotSame('*', $unlisted->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_contact_html_and_unicode_are_stored_as_plain_data(): void
    {
        Queue::fake([SendAdminAlert::class]);
        $message = 'Hello 👋 <script>alert("xss")</script>';

        $this->postJson('/api/v1/contact-messages', [
            'name' => 'Visitor 👑',
            'email' => 'visitor@example.com',
            'subject' => '<b>Question</b>',
            'message' => $message,
        ])->assertCreated()->assertHeader('Content-Type', 'application/json');

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Visitor 👑',
            'subject' => '<b>Question</b>',
            'message' => $message,
        ]);
    }

    public function test_production_style_500_response_does_not_expose_exception_details(): void
    {
        config(['app.debug' => false]);

        Route::middleware('api')->get('/api/v1/__exception-probe', function (): never {
            throw new RuntimeException('private SQLSTATE and C:\\secret\\application.php');
        });

        $response = $this->getJson('/api/v1/__exception-probe');

        $response->assertStatus(500)
            ->assertJsonMissing(['message' => 'private SQLSTATE and C:\\secret\\application.php']);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertStringNotContainsString('application.php', $response->getContent());
        $this->assertStringNotContainsString('trace', strtolower($response->getContent()));
    }
}
