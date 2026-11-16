<?php

namespace App\Services\Trading212\Exceptions;

/**
 * Raised when an order request got no usable answer (connection error, timeout or 5xx), so
 * Trading 212 may or may not have placed the order. The local TradeOrder is kept as `unknown`
 * and reconciled by the next account sync; the caller must not retry the order.
 */
class OrderOutcomeUnknownException extends Trading212Exception
{
    public function __construct(
        public readonly string $ticker,
        public readonly int $tradeOrderId,
        Trading212Exception $previous,
    ) {
        parent::__construct(self::messageFor($ticker, $previous->getMessage()), $previous->status, $previous->body);
    }

    public static function messageFor(string $ticker, string $reason): string
    {
        return "The outcome of the {$ticker} order is unknown: Trading 212 did not confirm whether it was accepted "
            ."({$reason}). The order may have been placed. It is recorded as unknown and will be reconciled at the "
            .'next account sync. Do not place it again; new orders for this ticker are blocked until then.';
    }
}
