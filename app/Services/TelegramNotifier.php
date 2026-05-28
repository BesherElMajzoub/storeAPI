<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    /**
     * Send an HTTPS POST alert to the Telegram admin chat.
     *
     * @param string $message
     * @return void
     */
    public function sendAdminAlert(string $message): void
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.admin_chat_id');

        if (empty($botToken) || empty($chatId)) {
            Log::warning('TelegramNotifier: Telegram credentials are not fully configured.', [
                'has_token' => !empty($botToken),
                'has_chat_id' => !empty($chatId),
            ]);
            return;
        }

        try {
            $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

            $response = Http::timeout(10)
                ->post($url, [
                    'chat_id' => $chatId,
                    'text' => $message,
                    'parse_mode' => 'Markdown',
                ]);

            if ($response->failed()) {
                Log::error('TelegramNotifier: API request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'message' => $message,
                ]);
                throw new \RuntimeException("Telegram API failed with status: " . $response->status());
            }
        } catch (\Throwable $e) {
            Log::error('TelegramNotifier: Exception occurred while sending alert.', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
                'message' => $message,
            ]);
            throw $e;
        }
    }
}
