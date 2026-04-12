<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $token,
        public string $email
    ) {}

    public function build()
    {
        $baseUrl = config('app.frontend_url', 'http://localhost:8000');

        $url = rtrim($baseUrl, '/')
            .'/reset-password?token='.urlencode($this->token)
            .'&email='.urlencode($this->email);

        return $this->subject('Reset Your Password')
            ->view('emails.reset')
            ->with([
                'url' => $url,
                'email' => $this->email,
            ]);
    }
}
