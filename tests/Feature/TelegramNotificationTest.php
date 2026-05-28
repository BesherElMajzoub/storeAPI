<?php

namespace Tests\Feature;

use App\Jobs\SendAdminAlert;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\TelegramNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TelegramNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->product = Product::factory()->create([
            'price' => 50.00,
            'in_stock' => true,
            'status' => 'published',
        ]);
    }

    /**
     * Test TelegramNotifier service sends request successfully.
     */
    public function test_telegram_notifier_sends_alert(): void
    {
        config([
            'services.telegram.bot_token' => 'test-bot-token',
            'services.telegram.admin_chat_id' => 'test-chat-id',
        ]);

        Http::fake([
            'https://api.telegram.org/bottest-bot-token/sendMessage' => Http::response(['ok' => true], 200),
        ]);

        $notifier = new TelegramNotifier();
        $notifier->sendAdminAlert('Hello World');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.telegram.org/bottest-bot-token/sendMessage'
                && $request['chat_id'] === 'test-chat-id'
                && $request['text'] === 'Hello World'
                && $request['parse_mode'] === 'Markdown';
        });
    }

    /**
     * Test TelegramNotifier logs warning and does not make Http request if not configured.
     */
    public function test_telegram_notifier_does_not_send_if_unconfigured(): void
    {
        config([
            'services.telegram.bot_token' => '',
            'services.telegram.admin_chat_id' => '',
        ]);

        Http::fake();

        $notifier = new TelegramNotifier();
        $notifier->sendAdminAlert('Hello World');

        Http::assertNothingSent();
    }

    /**
     * Test SendAdminAlert job uses TelegramNotifier to send message.
     */
    public function test_send_admin_alert_job_sends_notification(): void
    {
        $mockNotifier = $this->mock(TelegramNotifier::class);
        $mockNotifier->shouldReceive('sendAdminAlert')
            ->once()
            ->with('Test Alert Message');

        $job = new SendAdminAlert('Test Alert Message');
        $job->handle($mockNotifier);
    }

    /**
     * Test Stripe webhook dispatches SendAdminAlert when checkout session is completed.
     */
    public function test_stripe_webhook_dispatches_telegram_alert(): void
    {
        Queue::fake();

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending_payment',
            'payment_status' => 'unpaid',
            'stripe_session_id' => 'cs_test_done',
            'total' => 100.00,
        ]);

        // Add order items
        $order->items()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'price' => 50.00,
            'quantity' => 2,
            'total' => 100.00,
        ]);

        $payload = json_encode([
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_done',
                    'payment_intent' => 'pi_test_abc',
                    'metadata' => ['order_id' => (string) $order->id],
                ],
            ],
        ]);

        $secret = 'whsec_test_secret';
        $timestamp = time();
        $sigHeader = "t={$timestamp},v1=" . hash_hmac('sha256', "{$timestamp}.{$payload}", $secret);

        config(['services.stripe.webhook_secret' => $secret]);

        $response = $this->postJson('/api/v1/webhooks/stripe', json_decode($payload, true), [
            'Stripe-Signature' => $sigHeader,
        ]);

        $response->assertStatus(200);

        Queue::assertPushed(SendAdminAlert::class, function ($job) use ($order) {
            return $job->queue === 'notifications'
                && str_contains($job->message, "🛒 New order {$order->order_number}")
                && str_contains($job->message, "$100.00")
                && str_contains($job->message, "2 items");
        });
    }

    /**
     * Test new contact message submission dispatches SendAdminAlert.
     */
    public function test_contact_message_submission_dispatches_telegram_alert(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/v1/contact-messages', [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'subject' => 'Question about my order',
            'message' => 'Hello, I have a question.',
        ]);

        $response->assertStatus(201);

        Queue::assertPushed(SendAdminAlert::class, function ($job) {
            return $job->queue === 'notifications'
                && str_contains($job->message, "📩 New message from John Doe (john@example.com): \"Question about my order\"");
        });
    }

    /**
     * Test order cancellation request dispatches SendAdminAlert.
     */
    public function test_order_cancellation_request_dispatches_telegram_alert(): void
    {
        Queue::fake();

        $order = Order::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'pending',
            'payment_status' => 'paid',
            'total' => 100.00,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/cancellation-request", [
                'reason' => 'I ordered by mistake and need to cancel.',
            ]);

        $response->assertStatus(201);

        Queue::assertPushed(SendAdminAlert::class, function ($job) use ($order) {
            return $job->queue === 'notifications'
                && str_contains($job->message, "⚠️ Cancellation request for order {$order->order_number}")
                && str_contains($job->message, "I ordered by mistake and need to cancel.");
        });
    }
}
