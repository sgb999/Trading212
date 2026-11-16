<?php

namespace App\Services\Trading212\Exceptions;

/**
 * Raised locally, before any request is sent, when another order on the same account is still
 * being authorised and placed and its lock could not be taken in time. The order is not queued
 * or retried. Also raised when the lock lapsed while the order was being authorised, before it was
 * saved or sent. Uses status 409 (conflict); nothing was sent to the API.
 */
class OrderInProgressException extends Trading212Exception
{
    public function __construct(?string $message = null)
    {
        parent::__construct($message ?? self::message(), 409);
    }

    public static function message(): string
    {
        return 'Another order on this account is still being placed. This order was not sent and will not be '
            .'retried; check pending orders and order history, then place it again if it is still wanted.';
    }

    public static function lockLapsedMessage(): string
    {
        return 'Authorising this order took longer than its account lock allows, so another order may now be '
            .'in progress. This order was not sent and will not be retried; check pending orders and order '
            .'history, then place it again if it is still wanted.';
    }
}
