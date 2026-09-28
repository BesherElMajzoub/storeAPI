<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramNotifier
{
    /**
     * Send an HTTPS POST alert to the Telegram admin chat.
     *
     * Uses HTML parse_mode (safer than Markdown for user-generated content)
     * and falls back to plain text if formatting still fails.
     */
    public function sendAdminAlert(string $message): void
    {
        $botToken = config('services.telegram.bot_token');
        $chatId = config('services.telegram.admin_chat_id');

        if (empty($botToken) || empty($chatId)) {
            Log::warning('TelegramNotifier: Telegram credentials are not fully configured.', [
                'has_token' => ! empty($botToken),
                'has_chat_id' => ! empty($chatId),
            ]);

            return;
        }

        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";

        try {
            // First attempt: send with HTML parse_mode (more forgiving than Markdown)
            $escapedMessage = $this->escapeHtml($message);
            $response = Http::timeout(10)->post($url, [
                'chat_id' => $chatId,
                'text' => $escapedMessage,
                'parse_mode' => 'HTML',
            ]);

            // If HTML parsing failed (400), retry without any parse_mode (plain text)
            if ($response->status() === 400) {
                Log::warning('TelegramNotifier: HTML parse failed, retrying as plain text.', [
                    'body' => $response->body(),
                ]);

                $response = Http::timeout(10)->post($url, [
                    'chat_id' => $chatId,
                    'text' => $message,
                ]);
            }

            if ($response->failed()) {
                Log::error('TelegramNotifier: API request failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'message' => $message,
                ]);
                throw new \RuntimeException('Telegram API failed with status: '.$response->status());
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

    /**
     * Escape special HTML characters so Telegram's HTML parser does not choke.
     */
    private function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
