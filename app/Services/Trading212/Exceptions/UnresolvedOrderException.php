<?php

namespace App\Services\Trading212\Exceptions;

/**
 * Raised locally, before any request is sent, when an earlier order on the same ticker and
 * account is still being sent or has an unknown outcome (its request timed out or failed with a 5xx) and is still
 * being reconciled with Trading 212. Uses status 409 (conflict); nothing was sent to the API.
 */
class UnresolvedOrderException extends Trading212Exception
{
    public function __construct(string $ticker)
    {
        parent::__construct(self::messageFor($ticker), 409);
    }

    public static function messageFor(string $ticker): string
    {
        return "An earlier order for {$ticker} on this account is still being sent or has an unknown outcome "
            .'(Trading 212 did not confirm whether it was accepted) and is being reconciled. It may have been '
            .'placed. New orders for this ticker are blocked until the next account sync resolves it.';
    }
}
