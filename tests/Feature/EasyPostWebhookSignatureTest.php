<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exercises the real EasyPostService (no mock) so a broken signature-verification
 * call surfaces as a test failure instead of a 500 in production.
 */
class EasyPostWebhookSignatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_webhook_is_rejected_not_a_server_error(): void
    {
        config(['services.easypost.webhook_secret' => 'whsec_test']);

        $this->postJson('/api/v1/webhooks/easypost', ['description' => 'tracker.updated'])
            ->assertStatus(401);
    }

    public function test_badly_signed_webhook_is_rejected_not_a_server_error(): void
    {
        config(['services.easypost.webhook_secret' => 'whsec_test']);

        $this->withHeaders(['X-Hmac-Signature' => 'hmac-sha256-hex=bogus'])
            ->postJson('/api/v1/webhooks/easypost', ['description' => 'tracker.updated'])
            ->assertStatus(401);
    }

    public function test_correctly_signed_webhook_is_accepted(): void
    {
        $secret = 'whsec_test';
        config(['services.easypost.webhook_secret' => $secret]);

        $payload = json_encode(['description' => 'tracker.updated']);
        $signature = 'hmac-sha256-hex='.hash_hmac('sha256', $payload, $secret);

        $this->call(
            'POST',
            '/api/v1/webhooks/easypost',
            [],
            [],
            [],
            ['HTTP_X-Hmac-Signature' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $payload
        )->assertStatus(200);
    }
}
