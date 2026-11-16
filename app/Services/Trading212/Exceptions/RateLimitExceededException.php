<?php

namespace App\Services\Trading212\Exceptions;

/**
 * Raised when a request cannot be made within the configured wait budget without breaking
 * Trading 212's rate limits.
 */
class RateLimitExceededException extends Trading212Exception
{
    public function __construct(public readonly string $endpoint, public readonly int $retryAfter)
    {
        parent::__construct("Trading 212 rate limit reached for [{$endpoint}]. Retry in {$retryAfter}s.", 429);
    }
}
