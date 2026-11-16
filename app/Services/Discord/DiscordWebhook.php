<?php

namespace App\Services\Discord;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts messages to the Discord webhook configured in `services.discord.webhook_url`.
 *
 * Sending is best effort: with no webhook configured nothing is sent, and any transport
 * or HTTP error is logged instead of thrown so notifications never break trading or syncing.
 */
class DiscordWebhook
{
    private const int CONNECT_TIMEOUT_SECONDS = 3;

    private const int TIMEOUT_SECONDS = 5;

    public function isConfigured(): bool
    {
        return filled(config('services.discord.webhook_url'));
    }

    /**
     * Returns whether Discord accepted the message.
     *
     * @param  array<string, mixed>  $payload  Discord webhook body (`content`, `embeds`, ...).
     */
    public function send(array $payload): bool
    {
        if (! $this->isConfigured()) {
            return false;
        }

        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->asJson()
                ->post((string) config('services.discord.webhook_url'), [
                    'allowed_mentions' => ['parse' => []],
                    ...$payload,
                ]);
        } catch (Throwable $e) {
            Log::warning('Discord webhook request failed.', ['message' => $e->getMessage()]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Discord webhook rejected the message.', [
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 500),
            ]);

            return false;
        }

        return true;
    }
}
