<?php

namespace App\Jobs;

use App\Enums\OrderSide;
use App\Enums\TradeOrderStatus;
use App\Events\PortfolioUpdated;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Trading212Client;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pulls the account summary, positions and pending orders of one Trading 212 account,
 * stores the account metadata, reconciles local TradeOrders and broadcasts
 * {@see PortfolioUpdated} only when the remote state actually changed.
 *
 * Rate-limit strategy: a sync costs exactly 3 requests (summary 1/5s, positions 1/1s,
 * pending orders 1/5s) and reads through the client's short response cache, so it never
 * competes with the UI for more than one slot per endpoint. Local orders that are no longer
 * pending are resolved with a single `GET /equity/history/orders` page (20 req/min, cached
 * 60s) instead of one `GET /equity/orders/{id}` per order (1 req/s, and only for pending
 * orders). If that history call is rate limited the reconciliation is simply retried on
 * the next sync.
 *
 * Orders whose placement had an unknown outcome (see {@see TradeOrderStatus::Unknown}) have no
 * external id, so they are matched against pending orders and the same history page by ticker,
 * signed quantity and creation time. An unknown order still unmatched by a successful lookup
 * that covers its time window is marked failed once it is older than 30 minutes.
 */
class SyncTradingAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Rate-limit releases count as attempts; after the last one the job gives up quietly
     * (the scheduler dispatches a new one every minute anyway).
     */
    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /**
     * Number of recent historical orders inspected when reconciling (API max per page).
     */
    private const int HISTORY_PAGE_SIZE = 50;

    /**
     * A remote order matches an unknown local order when it was created between this many minutes
     * before and {@see self::MATCH_WINDOW_AFTER_MINUTES} after the local `created_at`.
     */
    private const int MATCH_WINDOW_BEFORE_MINUTES = 1;

    private const int MATCH_WINDOW_AFTER_MINUTES = 5;

    /**
     * Age after which an unknown order with no remote match is considered never placed.
     */
    private const int UNKNOWN_ORDER_EXPIRY_MINUTES = 30;

    public function __construct(public TradingAccount $tradingAccount) {}

    public function uniqueId(): string
    {
        return (string) $this->tradingAccount->getKey();
    }

    public function handle(): void
    {
        $account = $this->tradingAccount;
        $client = $account->client();

        try {
            $summary = $client->accountSummary();
            $positions = $client->positions();
            $pendingOrders = $client->pendingOrders();
        } catch (RateLimitExceededException $e) {
            $this->handleRateLimit($e);

            return;
        } catch (Trading212Exception $e) {
            Log::warning('Trading 212 sync failed.', [
                'trading_account_id' => $account->getKey(),
                'status' => $e->status,
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $account->update([
            'external_account_id' => $summary['id'] ?? $account->external_account_id,
            'currency' => $summary['currency'] ?? $account->currency,
            'last_synced_at' => now(),
        ]);

        $this->reconcileOrders($client, $pendingOrders);
        $this->reconcileUnknownOrders($client, $pendingOrders);

        $hash = md5(json_encode([$summary, $positions, $pendingOrders]) ?: '');
        $cache = Cache::store(config('trading212.cache_store'));

        if ($cache->get(self::stateHashKey($account)) !== $hash) {
            $cache->forever(self::stateHashKey($account), $hash);

            PortfolioUpdated::dispatch($account);
        }
    }

    public static function stateHashKey(TradingAccount $account): string
    {
        return "t212:sync-hash:{$account->getKey()}";
    }

    /**
     * @param  list<array<string, mixed>>  $pendingOrders
     */
    private function reconcileOrders(Trading212Client $client, array $pendingOrders): void
    {
        $openOrders = $this->tradingAccount->tradeOrders()
            ->where('status', TradeOrderStatus::Submitted)
            ->whereNotNull('external_id')
            ->get();

        if ($openOrders->isEmpty()) {
            return;
        }

        $pendingById = collect($pendingOrders)->keyBy(fn (array $order): int => (int) ($order['id'] ?? 0));

        [$stillPending, $resolved] = $openOrders->partition(
            fn (TradeOrder $order): bool => $pendingById->has((int) $order->external_id),
        );

        foreach ($stillPending as $order) {
            $order->update([
                'external_status' => $pendingById[(int) $order->external_id]['status'] ?? $order->external_status,
            ]);
        }

        if ($resolved->isNotEmpty()) {
            $this->resolveFromHistory($client, $resolved);
        }
    }

    /**
     * @param  Collection<int, TradeOrder>  $orders
     */
    private function resolveFromHistory(Trading212Client $client, Collection $orders): void
    {
        try {
            $history = $client->orderHistory(limit: self::HISTORY_PAGE_SIZE);
        } catch (Trading212Exception $e) {
            Log::info('Trading 212 order history unavailable; reconciliation deferred.', [
                'trading_account_id' => $this->tradingAccount->getKey(),
                'message' => $e->getMessage(),
            ]);

            return;
        }

        $historyById = collect($history['items'])
            ->filter(fn (array $item): bool => isset($item['order']['id']))
            ->keyBy(fn (array $item): int => (int) $item['order']['id']);

        foreach ($orders as $order) {
            $externalStatus = $historyById->get((int) $order->external_id)['order']['status'] ?? null;

            if ($externalStatus === null) {
                continue;
            }

            $order->update([
                'external_status' => $externalStatus,
                'status' => match ($externalStatus) {
                    'FILLED' => TradeOrderStatus::Filled,
                    'CANCELLED' => TradeOrderStatus::Cancelled,
                    'REJECTED' => TradeOrderStatus::Failed,
                    default => $order->status,
                },
            ]);
        }
    }

    /**
     * Resolves this account's unknown orders: first from the pending orders, then from one page
     * of order history. A failed or rate-limited history lookup leaves them unknown.
     *
     * @param  list<array<string, mixed>>  $pendingOrders
     */
    private function reconcileUnknownOrders(Trading212Client $client, array $pendingOrders): void
    {
        $unknownOrders = $this->tradingAccount->tradeOrders()
            ->where('status', TradeOrderStatus::Unknown)
            ->whereNull('external_id')
            ->oldest()
            ->oldest('id')
            ->get();

        if ($unknownOrders->isEmpty()) {
            return;
        }

        $claimedIds = $this->tradingAccount->tradeOrders()
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $unmatched = $this->matchUnknownOrders($unknownOrders, $pendingOrders, $claimedIds);

        if ($unmatched->isEmpty()) {
            return;
        }

        try {
            $history = $client->orderHistory(limit: self::HISTORY_PAGE_SIZE);
        } catch (Trading212Exception $e) {
            Log::info('Trading 212 order history unavailable; unknown orders left unresolved.', [
                'trading_account_id' => $this->tradingAccount->getKey(),
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
     * is never reused.
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
        $remoteTicker = $remote['ticker'] ?? $remote['instrument']['ticker'] ?? null;
        $remoteQuantity = $this->remoteSignedQuantity($remote);
        $remoteCreatedAt = $this->remoteCreatedAt($remote);

        if ((int) ($remote['id'] ?? 0) <= 0 || ! is_string($remoteTicker)
            || $remoteQuantity === null || $remoteCreatedAt === null) {
            return false;
        }

        $localQuantity = (float) $order->quantity * ($order->side === OrderSide::Sell ? -1 : 1);

        return mb_strtoupper($remoteTicker) === mb_strtoupper($order->ticker)
            && abs($remoteQuantity - $localQuantity) < 1e-8
            && $remoteCreatedAt->between(
                $order->created_at->copy()->subMinutes(self::MATCH_WINDOW_BEFORE_MINUTES),
                $order->created_at->copy()->addMinutes(self::MATCH_WINDOW_AFTER_MINUTES),
            );
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

        $windowStart = $order->created_at->copy()->subMinutes(self::MATCH_WINDOW_BEFORE_MINUTES);

        return $isLastPage || collect($historyOrders)->contains(
            fn (array $remote): bool => $this->remoteCreatedAt($remote)?->lt($windowStart) ?? false,
        );
    }

    private function handleRateLimit(RateLimitExceededException $e): void
    {
        if ($this->attempts() < $this->tries) {
            $this->release($e->retryAfter);

            return;
        }

        Log::info('Trading 212 sync skipped: rate limit still exhausted.', [
            'trading_account_id' => $this->tradingAccount->getKey(),
            'endpoint' => $e->endpoint,
        ]);
    }
}
