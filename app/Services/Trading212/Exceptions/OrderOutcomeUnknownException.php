<?php

namespace App\Services\Trading212\Exceptions;

/**
 * Raised when an order request got no usable answer (connection error, timeout or 5xx), or a success
 * response without an order id, so it is not known whether Trading 212 placed the order. The local
 * TradeOrder is kept as `unknown` and reconciled by the next account sync; the caller must not retry
 * the order.
 */
class OrderOutcomeUnknownException extends Trading212Exception
{
    /**
     * @param  array<string, mixed>|null  $body
     */
    public function __construct(
        public readonly string $ticker,
        public readonly int $tradeOrderId,
        string $reason,
        int $status = 0,
        ?array $body = null,
        ?Trading212Exception $previous = null,
    ) {
        parent::__construct(self::messageFor($ticker, $reason), $status, $body, $previous);
    }

    /**
     * The order request itself failed in a way that leaves its outcome unknown.
     */
    public static function fromRequestFailure(string $ticker, int $tradeOrderId, Trading212Exception $previous): self
    {
        return new self($ticker, $tradeOrderId, $previous->getMessage(), $previous->status, $previous->body, $previous);
    }

    /**
     * Trading 212 answered with a success response that carries no order id.
     *
     * @param  array<string, mixed>  $response
     */
    public static function missingOrderId(string $ticker, int $tradeOrderId, array $response): self
    {
        return new self($ticker, $tradeOrderId, 'Trading 212 accepted the request but returned no order id', body: $response);
    }

    public static function messageFor(string $ticker, string $reason): string
    {
        return "The outcome of the {$ticker} order is unknown: Trading 212 did not confirm whether it was accepted "
            ."({$reason}). The order may have been placed. It is recorded as unknown and will be reconciled at the "
            .'next account sync. Do not place it again; new orders for this ticker are blocked until then.';
    }
}
