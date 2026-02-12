<?php

namespace App\Services;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    private int $length;
    private int $ttlMinutes;
    private int $maxAttempts;
    private int $resendCooldownSeconds;
    private int $dailySendLimit;
    private ?string $emailOverride;
    private bool $logCodes;

    public function __construct()
    {
        $this->length = (int) config('otp.length', 6);
        $this->ttlMinutes = (int) config('otp.ttl_minutes', 10);
        $this->maxAttempts = (int) config('otp.max_attempts', 5);
        $this->resendCooldownSeconds = (int) config('otp.resend_cooldown_seconds', 60);
        $this->dailySendLimit = (int) config('otp.daily_send_limit', 5);
        $this->emailOverride = config('otp.email_override');
        $this->logCodes = (bool) config('otp.log_codes', false);
    }

    public function send(string $identifier, string $purpose, string $channel = 'email'): array
    {
        $now = now();
        $otp = OtpCode::firstOrNew([
            'identifier' => $identifier,
            'purpose' => $purpose,
            'channel' => $channel,
        ]);

        if ($otp->exists && $otp->last_sent_at instanceof Carbon) {
            $secondsSinceLast = $otp->last_sent_at->diffInSeconds($now);
            if ($secondsSinceLast < $this->resendCooldownSeconds) {
                return [
                    'status' => 'cooldown',
                    'retry_after' => $this->resendCooldownSeconds - $secondsSinceLast,
                ];
            }
        }

        $today = $now->toDateString();
        if ($otp->sent_count_date?->toDateString() !== $today) {
            $otp->sent_count = 0;
            $otp->sent_count_date = $today;
        }

        if ($otp->sent_count >= $this->dailySendLimit) {
            return [
                'status' => 'daily_limit',
            ];
        }

        $code = $this->generateCode();
        $otp->code_hash = hash('sha256', $code);
        $otp->expires_at = $now->copy()->addMinutes($this->ttlMinutes);
        $otp->attempts = 0;
        $otp->max_attempts = $this->maxAttempts;
        $otp->last_sent_at = $now;
        $otp->sent_count = (int) $otp->sent_count + 1;
        $otp->sent_count_date = $today;
        $otp->consumed_at = null;
        $otp->save();

        if ($channel === 'email') {
            $this->sendEmail($identifier, $code, $purpose);
        }

        return [
            'status' => 'sent',
        ];
    }

    public function verify(string $identifier, string $purpose, string $code, string $channel = 'email'): array
    {
        $otp = OtpCode::query()
            ->where('identifier', $identifier)
            ->where('purpose', $purpose)
            ->where('channel', $channel)
            ->first();

        if (!$otp) {
            return ['status' => 'invalid'];
        }

        if ($otp->consumed_at !== null) {
            return ['status' => 'consumed'];
        }

        if ($otp->expires_at && $otp->expires_at->isPast()) {
            return ['status' => 'expired'];
        }

        if ($otp->attempts >= $otp->max_attempts) {
            return ['status' => 'locked'];
        }

        if (!hash_equals($otp->code_hash, hash('sha256', $code))) {
            $otp->increment('attempts');
            return ['status' => 'invalid'];
        }

        $otp->consumed_at = now();
        $otp->save();

        return ['status' => 'verified'];
    }

    private function generateCode(): string
    {
        $min = (int) pow(10, $this->length - 1);
        $max = (int) pow(10, $this->length) - 1;

        return (string) random_int($min, $max);
    }

    private function sendEmail(string $identifier, string $code, string $purpose): void
    {
        $sendTo = $this->emailOverride ?: $identifier;
        $sentToOverride = !empty($this->emailOverride) && $this->emailOverride !== $identifier;

        Mail::to($sendTo)->send(new OtpCodeMail(
            code: $code,
            purpose: $purpose,
            expiresInMinutes: $this->ttlMinutes,
            intendedFor: $identifier,
            deliveredTo: $sendTo,
            sentToOverride: $sentToOverride
        ));

        if ($this->logCodes) {
            Log::info('OTP code generated', [
                'identifier' => $identifier,
                'purpose' => $purpose,
                'channel' => 'email',
                'code' => $code,
                'delivered_to' => $sendTo,
                'sent_to_override' => $sentToOverride,
            ]);
        }
    }
}
