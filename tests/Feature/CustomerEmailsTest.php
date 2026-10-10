<?php

namespace Tests\Feature;

use App\Mail\CancellationRequestDecidedMail;
use App\Mail\OrderConfirmedMail;
use App\Mail\OtpCodeMail;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerEmailsTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_confirmation_lists_the_order_and_the_free_cancel_deadline(): void
    {
        config(['orders.direct_cancel_window_minutes' => 180, 'orders.store_timezone' => 'America/Los_Angeles', 'app.frontend_url' => 'https://shop.test']);
        $order = Order::factory()->for(User::factory()->create())->create([
            'status' => 'processing', 'payment_status' => 'authorized', 'total' => 100,
            'authorized_at' => now()->setTimezone('UTC')->setDate(2026, 10, 8)->setTime(18, 0),
            'shipping_address' => ['name' => 'Ana', 'line1' => '1 Main St', 'city' => 'Redondo Beach', 'state' => 'CA', 'postal_code' => '90277'],
        ]);
        $order->items()->create(['product_name' => 'Silk Dress', 'price' => 50, 'quantity' => 2, 'total' => 100]);
        $this->travelTo($order->authorized_at->copy()->addMinutes(5));

        $html = (new OrderConfirmedMail($order))->render();

        $this->assertStringContainsString('Order confirmed', $html);
        $this->assertStringContainsString($order->order_number, $html);
        $this->assertStringContainsString('Silk Dress', $html);
        $this->assertStringContainsString('Redondo Beach, CA 90277', $html);
        $this->assertStringContainsString('October 8, 2026 at 2:00 PM', $html);
        $this->assertStringContainsString('https://shop.test/orders/'.$order->id, $html);
        $this->assertStringNotContainsString('Laravel', $html);
        $this->assertStringContainsString('Otantik Queen', $html);
    }

    public function test_cancellation_email_says_no_charge_for_a_released_hold(): void
    {
        config(['app.frontend_url' => 'https://shop.test']);
        $request = $this->request(['payment_status' => 'authorized', 'paid_at' => null]);
        $html = (new CancellationRequestDecidedMail($request, 'accepted'))->render();

        $this->assertStringContainsString('You were not charged', $html);
        $this->assertStringNotContainsString('refund has been initiated', $html);
        $this->assertStringContainsString('https://shop.test/orders/'.$request->order_id, $html);
        $this->assertStringNotContainsString('/orders/'.$request->order->order_number, $html);
    }

    public function test_cancellation_email_promises_a_refund_only_after_a_capture(): void
    {
        $html = (new CancellationRequestDecidedMail($this->request(['payment_status' => 'paid', 'paid_at' => now()]), 'accepted'))->render();

        $this->assertStringContainsString('Your refund is on its way', $html);
        $this->assertStringNotContainsString('You were not charged', $html);
    }

    public function test_otp_email_is_branded_and_has_plain_wording(): void
    {
        $html = (new OtpCodeMail('123456', 'email_verification', 10, 'a@b.test', 'a@b.test', false))->render();

        $this->assertStringContainsString('Enter this code to verify your email', $html);
        $this->assertStringNotContainsString('Verification Code for', $html);
        $this->assertStringContainsString('Otantik Queen', $html);
    }

    private function request(array $orderState): OrderCancellationRequest
    {
        $user = User::factory()->create();
        $order = Order::factory()->for($user)->create(['status' => 'cancelled'] + $orderState);

        return OrderCancellationRequest::create(['order_id' => $order->id, 'user_id' => $user->id, 'reason' => 'Changed my mind.', 'status' => 'accepted']);
    }
}
