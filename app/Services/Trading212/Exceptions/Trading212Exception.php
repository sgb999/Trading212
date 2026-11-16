<?php

namespace App\Services\Trading212\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Raised when the Trading 212 API returns an error response.
 */
class Trading212Exception extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(string $message, public readonly int $status = 0, public readonly ?array $body = null)
    {
        parent::__construct($message, $status);
    }

    public static function fromResponse(string $endpoint, Response $response): self
    {
        $body = $response->json();
        $detail = is_array($body)
            ? ($body['message'] ?? $body['errorMessage'] ?? $body['code'] ?? $body['type'] ?? null)
            : null;
        $detail ??= trim((string) $response->body()) ?: $response->reason();

        $message = match ($response->status()) {
            401 => 'Trading 212 rejected the API credentials (401). Check the key/secret and environment.',
            403 => 'The Trading 212 API key lacks the required scope for this action (403).',
            default => "Trading 212 request [{$endpoint}] failed ({$response->status()}): {$detail}",
        };

        return new self($message, $response->status(), is_array($body) ? $body : null);
    }
}
