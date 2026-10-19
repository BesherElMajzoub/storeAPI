<?php

namespace App\Mail;

use App\Models\Order;
use App\Models\RefundAttempt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** Sent once when an order's money is fully back with the customer, naming how it was returned. */
class OrderRefundedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function build(): self
    {
        $manual = RefundAttempt::query()
            ->where('order_id', $this->order->id)
            ->where('channel', RefundAttempt::CHANNEL_MANUAL)
            ->where('status', RefundAttempt::RECORDED)
            ->orderByDesc('attempt_no')
            ->first();

        return $this->subject("Your refund for order {$this->order->order_number}")
            ->view('emails.order_refunded')
            ->with([
                'order' => $this->order,
                'amount' => number_format((float) $this->order->refunded_amount, 2),
                'via' => $this->order->refunded_via,
                'manualMethod' => $manual ? ucfirst((string) $manual->method) : null,
                'manualSentAt' => $manual?->sent_at?->timezone(config('orders.store_timezone', 'America/Los_Angeles')),
                'orderUrl' => rtrim((string) config('app.frontend_url'), '/')."/orders/{$this->order->id}",
            ]);
    }
}
