<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once when checkout succeeds (card held or paid, or a free order):
 * what was ordered, where it goes, and until when it can be cancelled free.
 */
class OrderConfirmedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function build(): self
    {
        $order = $this->order->loadMissing('items');
        $cancellation = $order->customerCancellation();
        $cancelUntil = $cancellation['mode'] === 'direct'
            ? $cancellation['direct_until']?->copy()->setTimezone(config('orders.store_timezone', 'America/Los_Angeles'))
            : null;

        return $this->subject("Order confirmed: #{$order->order_number}")
            ->view('emails.order_confirmed')
            ->with([
                'order' => $order,
                'address' => $order->shipping_address ?? [],
                'cancelUntil' => $cancelUntil,
                'orderUrl' => rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->id}",
            ]);
    }
}
