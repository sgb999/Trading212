<?php

namespace App\Jobs;

use App\Actions\RecordPortfolioSnapshot;
use App\Enums\TradeOrderStatus;
use App\Events\PortfolioUpdated;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Services\Discord\DiscordWebhook;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Trading212Client;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

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
 * Failure alerting: consecutive failed syncs (Trading 212 errors or unexpected exceptions,
 * not rate-limit skips) are counted per account in the durable cache store. When the streak
 * reaches {@see self::FAILURE_ALERT_THRESHOLD} one Discord alert is posted; a successful
 * sync resets the streak.
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
     * Consecutive failed syncs after which a single Discord alert is posted.
     */
    public const int FAILURE_ALERT_THRESHOLD = 3;

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

            $this->recordFailure($e);

            return;
        }

        $account->update([
            'external_account_id' => $summary['id'] ?? $account->external_account_id,
            'currency' => $summary['currency'] ?? $account->currency,
            'last_synced_at' => now(),
        ]);

        try {
            app(RecordPortfolioSnapshot::class)->handle($account, $summary);
        } catch (Throwable $e) {
            Log::warning('Portfolio snapshot not recorded.', [
                'trading_account_id' => $account->getKey(),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $this->reconcileOrders($client, $pendingOrders);

        $hash = md5(json_encode([$summary, $positions, $pendingOrders]) ?: '');
        $cache = $this->cache();

        if ($cache->get(self::stateHashKey($account)) !== $hash) {
            $cache->forever(self::stateHashKey($account), $hash);

            PortfolioUpdated::dispatch($account);
        }

        $cache->forget(self::failureCountKey($account));
    }

    /**
     * Called by the queue when the job throws an unexpected exception.
     */
    public function failed(?Throwable $exception): void
    {
        $this->recordFailure($exception);
    }

    public static function stateHashKey(TradingAccount $account): string
    {
        return "t212:sync-hash:{$account->getKey()}";
    }

    public static function failureCountKey(TradingAccount $account): string
    {
        return "t212:sync-failures:{$account->getKey()}";
    }

    /**
     * Extends the account's failure streak and alerts Discord exactly once, when it hits the threshold.
     */
    private function recordFailure(?Throwable $exception): void
    {
        $cache = $this->cache();
        $key = self::failureCountKey($this->tradingAccount);

        $cache->add($key, 0);
        $consecutiveFailures = (int) $cache->increment($key);

        if ($consecutiveFailures !== self::FAILURE_ALERT_THRESHOLD) {
            return;
        }

        app(DiscordWebhook::class)->send([
            'content' => sprintf(
                'Trading 212 sync failed %d times in a row for account "%s" (#%d). Last error: %s',
                $consecutiveFailures,
                DiscordWebhook::escapeMarkdown((string) $this->tradingAccount->name),
                $this->tradingAccount->getKey(),
                $exception === null ? 'unknown' : DiscordWebhook::escapeMarkdown(mb_substr($exception->getMessage(), 0, 500)),
            ),
        ]);
    }

    private function cache(): Repository
    {
        return Cache::store(config('trading212.cache_store'));
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
