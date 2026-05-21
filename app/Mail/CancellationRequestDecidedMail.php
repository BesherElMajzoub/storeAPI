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
                'decision'           => $this->decision,
                'orderNumber'        => $order->order_number,
                'adminNote'          => $this->cancellationRequest->admin_note,
                'decidedAt'          => $this->cancellationRequest->decided_at,
            ]);
    }
}
