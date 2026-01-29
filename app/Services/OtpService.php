<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class OtpService
{
    private int $length;
    private int $ttlMinutes;
    private int $maxAttempts;
    private int $resendCooldownSeconds;
    private int $dailySendLimit;

    public function __construct()
    {
        $this->length = 6;
        $this->ttlMinutes = 10;
        $this->maxAttempts = 5;
        $this->resendCooldownSeconds = 60;
        $this->dailySendLimit = 5;
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

        $this->sendStub($identifier, $code, $purpose, $channel);

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

    private function sendStub(string $identifier, string $code, string $purpose, string $channel): void
    {
        Log::info('OTP code generated', [
            'identifier' => $identifier,
            'purpose' => $purpose,
            'channel' => $channel,
            'code' => $code,
        ]);
    }
}
