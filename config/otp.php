<?php

return [
    'length' => (int) env('OTP_LENGTH', 6),
    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),
    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),
    'daily_send_limit' => (int) env('OTP_DAILY_SEND_LIMIT', 5),
    'email_override' => env('OTP_EMAIL_OVERRIDE'),
    'email_subject' => env('OTP_EMAIL_SUBJECT', 'Your verification code'),
    'log_codes' => (bool) env('OTP_LOG_CODES', false),
];
