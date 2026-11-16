<?php

namespace App\Actions;

use App\Enums\OrderSide;
use App\Enums\TradeOrderStatus;
use App\Jobs\SyncTradingAccount;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Trading212Client;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Resolves an account's orders whose placement outcome is not known (see {@see TradeOrderStatus::Unknown}),
 * and orders left in {@see TradeOrderStatus::Sending} once their request can no longer be in flight.
 * They have no external id, so they are matched against pending orders and one page of order history
 * by ticker, order type, signed quantity, API origin and creation time. One still unmatched by a
 * successful lookup that covers its time window is marked failed once it is older than 30 minutes.
 * Called by {@see SyncTradingAccount}.
 */
class ReconcileUnknownOrders
{
    /**
     * Clock-skew allowance, either side of the match window, between this server and Trading 212.
     */
    private const int CLOCK_SKEW_SECONDS = 60;

    /**
     * Largest difference between two quantities still treated as the same order, so a quantity that
     * Trading 212 rounds or normalises (the API sends floats; the column keeps 8 decimals) still
     * matches. Safe because at most one unresolved local order per ticker can exist at a time.
     */
    private const float QUANTITY_TOLERANCE = 0.0001;

    /**
     * Age after which an unknown order with no remote match is considered never placed.
     */
    private const int UNKNOWN_ORDER_EXPIRY_MINUTES = 30;

    /**
     * Resolves this account's unknown orders, and `sending` orders whose request can no longer be in
     * flight: first from the pending orders, then from one page of order history. A failed or
     * rate-limited history lookup leaves them as they are.
     *
     * @param  list<array<string, mixed>>  $pendingOrders
     */
    public function handle(TradingAccount $account, Trading212Client $client, array $pendingOrders): void
    {
        $unknownOrders = $account->tradeOrders()
            ->whereNull('external_id')
            ->where(fn (Builder $query): Builder => $query
                ->where('status', TradeOrderStatus::Unknown)
                ->orWhere(fn (Builder $query): Builder => $query
                    ->where('status', TradeOrderStatus::Sending)
                    ->where('created_at', '<', now()->subSeconds(self::maxSendSeconds()))))
            ->oldest()
            ->oldest('id')
            ->get();

        if ($unknownOrders->isEmpty()) {
            return;
        }

        $claimedIds = $account->tradeOrders()
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $unmatched = $this->matchUnknownOrders($unknownOrders, $pendingOrders, $claimedIds);

        if ($unmatched->isEmpty()) {
            return;
        }

        try {
            $history = $client->orderHistory(limit: SyncTradingAccount::HISTORY_PAGE_SIZE);
        } catch (Trading212Exception $e) {
            Log::info('Trading 212 order history unavailable; unknown orders left unresolved.', [
                'trading_account_id' => $account->getKey(),
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $historyOrders = collect($history['items'])
            ->filter(fn (array $item): bool => is_array($item['order'] ?? null))
            ->map(fn (array $item): array => $item['order'])
            ->values()
            ->all();

        $unmatched = $this->matchUnknownOrders($unmatched, $historyOrders, $claimedIds);

        foreach ($unmatched as $order) {
            if ($this->shouldExpireUnknownOrder($order, $historyOrders, $history['nextPagePath'] === null)) {
                $order->update([
                    'status' => TradeOrderStatus::Failed,
                    'error' => 'Trading 212 gave no clear answer when this order was sent, and no matching order '
                        .'was found in its pending orders or order history after '.self::UNKNOWN_ORDER_EXPIRY_MINUTES
                        .' minutes, so it was treated as never placed.',
                ]);
            }
        }
    }

    /**
     * Assigns remote orders to unknown local orders (oldest local order first, closest creation
     * time wins). A remote id already stored on a local order, or assigned earlier in this sync,
     * is never reused. Every accepted order without a stored id is itself unresolved (PlaceOrder
     * records a success that returns no id as `unknown`), so it takes part in this matching instead
     * of silently owning a remote order that another unresolved order could then claim.
     *
     * @param  Collection<int, TradeOrder>  $unknownOrders
     * @param  list<array<string, mixed>>  $remoteOrders
     * @param  list<int>  $claimedIds  Every id assigned here is appended.
     * @return Collection<int, TradeOrder> The orders that are still unmatched
     */
    private function matchUnknownOrders(Collection $unknownOrders, array $remoteOrders, array &$claimedIds): Collection
    {
        $unmatched = new Collection;

        foreach ($unknownOrders as $order) {
            $match = collect($remoteOrders)
                ->filter(fn (array $remote): bool => ! in_array((int) ($remote['id'] ?? 0), $claimedIds, true)
                    && $this->remoteOrderMatches($order, $remote))
                ->sortBy(fn (array $remote): float => abs(
                    (float) ($this->remoteCreatedAt($remote)?->diffInSeconds($order->created_at) ?? PHP_INT_MAX),
                ))
                ->first();

            if ($match === null) {
                $unmatched->push($order);

                continue;
            }

            $claimedIds[] = (int) $match['id'];
            $externalStatus = is_string($match['status'] ?? null) ? $match['status'] : null;

            $order->update([
                'external_id' => (int) $match['id'],
                'external_status' => $externalStatus,
                'status' => match ($externalStatus) {
                    'FILLED' => TradeOrderStatus::Filled,
                    'CANCELLED' => TradeOrderStatus::Cancelled,
                    'REJECTED' => TradeOrderStatus::Failed,
                    default => TradeOrderStatus::Submitted,
                },
                'response' => $match,
            ]);
        }

        return $unmatched;
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function remoteOrderMatches(TradeOrder $order, array $remote): bool
    {
        $remoteQuantity = $this->remoteSignedQuantity($remote);
        $remoteCreatedAt = $this->remoteCreatedAt($remote);

        if ((int) ($remote['id'] ?? 0) <= 0 || $remoteQuantity === null || $remoteCreatedAt === null) {
            return false;
        }

        $localQuantity = (float) $order->quantity * ($order->side === OrderSide::Sell ? -1 : 1);

        return $this->remoteOrderIsSameInstruction($order, $remote)
            && abs($remoteQuantity - $localQuantity) <= self::QUANTITY_TOLERANCE
            && $remoteCreatedAt->between($this->matchWindowStart($order), $this->matchWindowEnd($order));
    }

    /**
     * Same ticker and order type, and placed through the API (when Trading 212 reports the origin).
     *
     * @param  array<string, mixed>  $remote
     */
    private function remoteOrderIsSameInstruction(TradeOrder $order, array $remote): bool
    {
        $remoteTicker = $remote['ticker'] ?? $remote['instrument']['ticker'] ?? null;
        $initiatedFrom = $remote['initiatedFrom'] ?? 'API';

        return is_string($remoteTicker)
            && mb_strtoupper($remoteTicker) === mb_strtoupper($order->ticker)
            && ($remote['type'] ?? null) === $order->type->value
            && $initiatedFrom === 'API';
    }

    /**
     * The local row is written just before the request is sent, so the remote order is created after
     * it (less clock skew) and at the latest when the request ends.
     */
    private function matchWindowStart(TradeOrder $order): CarbonInterface
    {
        return $order->created_at->copy()->subSeconds(self::CLOCK_SKEW_SECONDS);
    }

    private function matchWindowEnd(TradeOrder $order): CarbonInterface
    {
        return $order->created_at->copy()->addSeconds(self::maxSendSeconds() + self::CLOCK_SKEW_SECONDS);
    }

    /**
     * Longest an order request can take: each of up to two attempts (a 429 is retried once) may wait
     * for a rate-limit slot for `max_wait_seconds` and then run for `timeout` seconds.
     */
    private static function maxSendSeconds(): int
    {
        return 2 * ((int) config('trading212.timeout') + (int) config('trading212.max_wait_seconds'));
    }

    /**
     * The remote quantity signed by side (negative = sell). Sells may be reported either as a
     * negative quantity or as a positive quantity with `side: SELL`.
     *
     * @param  array<string, mixed>  $remote
     */
    private function remoteSignedQuantity(array $remote): ?float
    {
        if (! is_numeric($remote['quantity'] ?? null)) {
            return null;
        }

        $quantity = (float) $remote['quantity'];

        return match ($remote['side'] ?? null) {
            'SELL' => -abs($quantity),
            'BUY' => abs($quantity),
            default => $quantity,
        };
    }

    /**
     * @param  array<string, mixed>  $remote
     */
    private function remoteCreatedAt(array $remote): ?Carbon
    {
        $createdAt = $remote['createdAt'] ?? $remote['dateCreated'] ?? null;

        if (! is_string($createdAt) || $createdAt === '') {
            return null;
        }

        try {
            return Carbon::parse($createdAt);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * An unmatched unknown order expires only when it is old enough and the history page reaches
     * back past its match window (it is the last page, or it holds an order created before the
     * window), so an order that merely fell off the first page is never marked failed.
     *
     * @param  list<array<string, mixed>>  $historyOrders
     */
    private function shouldExpireUnknownOrder(TradeOrder $order, array $historyOrders, bool $isLastPage): bool
    {
        if ($order->created_at->gt(now()->subMinutes(self::UNKNOWN_ORDER_EXPIRY_MINUTES))) {
            return false;
        }

        $windowStart = $this->matchWindowStart($order);

        return $isLastPage || collect($historyOrders)->contains(
            fn (array $remote): bool => $this->remoteCreatedAt($remote)?->lt($windowStart) ?? false,
        );
    }
}
