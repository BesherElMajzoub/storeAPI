<?php

namespace App\Jobs;

use App\Services\TelegramNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendAdminAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array
     */
    public $backoff = [10, 30, 60];

    /**
     * The message to send.
     */
    public string $message;

    /**
     * Also email ADMIN_ALERT_EMAIL (for alerts that need action, not just a heads-up).
     */
    public bool $email;

    /**
     * Create a new job instance.
     */
    public function __construct(string $message, bool $email = false)
    {
        $this->message = $message;
        $this->email = $email;
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(TelegramNotifier $notifier): void
    {
        // Only on the first attempt, so Telegram retries don't repeat the email.
        if ($this->email && $this->attempts() <= 1 && filled($address = config('services.admin_alerts.email'))) {
            try {
                Mail::raw($this->message, fn ($mail) => $mail->to($address)->subject('['.config('app.name').'] Action needed'));
            } catch (\Throwable $e) {
                Log::error('Admin alert email could not be sent.', ['error' => $e->getMessage()]);
            }
        }

        $notifier->sendAdminAlert($this->message);
    }
}
