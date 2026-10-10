<?php

namespace App\Mail;

use App\Models\OrderCancellationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CancellationRequestDecidedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public OrderCancellationRequest $cancellationRequest,
        /** 'accepted' | 'rejected' */
        public string $decision
    ) {}

    public function build(): self
    {
        $order = $this->cancellationRequest->order;

        $subject = $this->decision === 'accepted'
            ? "Your cancellation request for order #{$order->order_number} has been accepted"
            : "Your cancellation request for order #{$order->order_number} has been rejected";

        return $this
            ->subject($subject)
            ->view('emails.cancellation_request_decided')
            ->with([
                'decision' => $this->decision,
                'orderNumber' => $order->order_number,
                'orderUrl' => rtrim((string) config('app.frontend_url'), '/')."/orders/{$order->id}",
                'adminNote' => $this->cancellationRequest->admin_note,
                'decidedAt' => $this->cancellationRequest->decided_at,
                // Money was only taken if the payment was captured; otherwise the card hold is released.
                'moneyOutcome' => $order->paid_at !== null || $order->refund_status === 'succeeded' ? 'refund' : 'released',
            ]);
    }
}
