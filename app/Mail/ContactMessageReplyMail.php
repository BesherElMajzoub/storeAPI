<?php

namespace App\Mail;

use App\Models\ContactMessageReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ContactMessageReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public ContactMessageReply $reply) {}

    public function build(): self
    {
        $message = $this->reply->message;

        return $this->subject('Re: '.($message->subject ?: 'Your message to '.config('mail.brand.name')))
            ->view('emails.contact_reply')
            ->with([
                'reply' => $this->reply,
                'original' => $message,
                'messagesUrl' => rtrim((string) config('app.frontend_url'), '/').'/messages',
            ]);
    }
}
