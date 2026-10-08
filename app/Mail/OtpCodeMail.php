<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
    use SerializesModels;

    public function __construct(
        public string $code,
        public string $purpose,
        public int $expiresInMinutes,
        public string $intendedFor,
        public string $deliveredTo,
        public bool $sentToOverride
    ) {}

    public function build()
    {
        $purposeLabel = match ($this->purpose) {
            'password_reset' => 'reset your password',
            'email_verification' => 'verify your email',
            default => str_replace('_', ' ', $this->purpose),
        };

        return $this->subject(config('otp.email_subject', 'Your Otantik Queen verification code'))
            ->view('emails.otp')
            ->with([
                'purposeLabel' => $purposeLabel,
                'code' => $this->code,
                'expiresInMinutes' => $this->expiresInMinutes,
                'intendedFor' => $this->intendedFor,
                'deliveredTo' => $this->deliveredTo,
                'sentToOverride' => $this->sentToOverride,
            ]);
    }
}
