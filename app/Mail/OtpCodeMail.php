<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
        public string $purpose,
        public int $expiresInMinutes,
        public string $intendedFor,
        public string $deliveredTo,
        public bool $sentToOverride
    ) {
    }

    public function build()
    {
        $purposeLabel = match ($this->purpose) {
            'password_reset' => 'Password reset',
            default => ucfirst(str_replace('_', ' ', $this->purpose)),
        };

        return $this->subject(config('otp.email_subject', 'Your verification code'))
            ->view('emails.otp')
            ->with([
                'purposeLabel' => $purposeLabel,
            ]);
    }
}
