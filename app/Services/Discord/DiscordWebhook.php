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

    private const string REDACTED_URL = '[discord-webhook-url]';

    private const string REDACTED_TOKEN = '[redacted]';

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
                    ...$payload,
                    'allowed_mentions' => ['parse' => []],
                ]);
        } catch (Throwable $e) {
            Log::warning('Discord webhook request failed.', [
                'exception' => $e::class,
                'message' => $this->redact($e->getMessage()),
            ]);

            return false;
        }

        if ($response->failed()) {
            Log::warning('Discord webhook rejected the message.', [
                'status' => $response->status(),
                'body' => mb_substr($this->redact($response->body()), 0, 500),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Backslash-escapes Discord markdown so untrusted text (names, tickers, error messages)
     * renders literally instead of as formatting or links.
     */
    public static function escapeMarkdown(string $text): string
    {
        return (string) preg_replace('/([\\\\*_~`|>\[\]()])/', '\\\\$1', $text);
    }

    /**
     * Removes the webhook URL (plain and URL-encoded) and its secret token from text bound for the logs.
     */
    private function redact(string $text): string
    {
        $url = (string) config('services.discord.webhook_url');

        if ($url === '') {
            return $text;
        }

        $text = str_replace([$url, rawurlencode($url), urlencode($url)], self::REDACTED_URL, $text);

        $token = basename((string) parse_url($url, PHP_URL_PATH));

        if (strlen($token) < 8) {
            return $text;
        }

        return str_replace([$token, rawurlencode($token), urlencode($token)], self::REDACTED_TOKEN, $text);
    }
}
