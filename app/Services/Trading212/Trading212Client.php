<?php

namespace App\Services\Trading212;

use App\Enums\OrderType;
use App\Enums\TimeValidity;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Rate-limited, cached client for the Trading 212 Public API (v0), for one TradingAccount.
 *
 * - All requests reserve a slot from {@see Trading212RateLimiter} first.
 * - GET responses are cached in the Postgres-backed cache per account for the endpoint's `cache_ttl`.
 *   Pass `fresh: true` to bypass the cache (still rate limited).
 * - Write operations flush the account's cached orders / positions / summary.
 *
 * Response payloads are returned as decoded arrays exactly as documented in
 * docs/trading212-openapi.yaml.
 */
final class Trading212Client
{
    /**
     * Endpoints whose cache is flushed after an order is placed or cancelled.
     */
    private const array TRADING_STATE_ENDPOINTS = ['account.summary', 'positions', 'orders.list'];

    private readonly Repository $cache;

    public function __construct(
        public readonly TradingAccount $account,
        private readonly Trading212RateLimiter $rateLimiter,
    ) {
        $this->cache = Cache::store(config('trading212.cache_store'));
    }

    public static function for(TradingAccount $account): self
    {
        return new self($account, app(Trading212RateLimiter::class));
    }

    /**
     * @return array{
     *     id?: int,
     *     currency?: string,
     *     totalValue?: float,
     *     cash?: array<string, float>,
     *     investments?: array<string, float>,
     * }
     */
    public function accountSummary(bool $fresh = false): array
    {
        return $this->get('account.summary', '/equity/account/summary', fresh: $fresh);
    }

    /**
     * Open positions, optionally filtered to a single ticker.
     *
     * @return list<array<string, mixed>>
     */
    public function positions(?string $ticker = null, bool $fresh = false): array
    {
        return $this->get('positions', '/equity/positions', array_filter(['ticker' => $ticker]), $fresh);
    }

    /**
     * Pending (working) orders.
     *
     * @return list<array<string, mixed>>
     */
    public function pendingOrders(bool $fresh = false): array
    {
        return $this->get('orders.list', '/equity/orders', fresh: $fresh);
    }

    /**
     * @return array<string, mixed>
     */
    public function order(int $orderId, bool $fresh = false): array
    {
        return $this->get('orders.show', "/equity/orders/{$orderId}", fresh: $fresh);
    }

    /**
     * Place an order. `$quantity` must already be signed (negative = sell).
     *
     * @return array<string, mixed> The created Order
     */
    public function placeOrder(
        OrderType $type,
        string $ticker,
        float $quantity,
        ?float $limitPrice = null,
        ?float $stopPrice = null,
        TimeValidity $timeValidity = TimeValidity::Day,
        bool $extendedHours = false,
    ): array {
        $payload = ['ticker' => $ticker, 'quantity' => $quantity];

        if ($type === OrderType::Market) {
            $payload['extendedHours'] = $extendedHours;
        } else {
            $payload['timeValidity'] = $timeValidity->value;
        }

        if ($type->requiresLimitPrice()) {
            $payload['limitPrice'] = $limitPrice;
        }

        if ($type->requiresStopPrice()) {
            $payload['stopPrice'] = $stopPrice;
        }

        $path = '/equity/orders/'.strtolower($type->value);

        $order = $this->send('post', $type->endpointKey(), $path, $payload)->json() ?? [];

        $this->flushTradingState();

        return $order;
    }

    public function cancelOrder(int $orderId): void
    {
        $this->send('delete', 'orders.cancel', "/equity/orders/{$orderId}");

        $this->flushTradingState();
    }

    /**
     * All tradable instruments (large payload, cached for hours).
     *
     * @return list<array<string, mixed>>
     */
    public function instruments(bool $fresh = false): array
    {
        return $this->get('metadata.instruments', '/equity/metadata/instruments', fresh: $fresh);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function exchanges(bool $fresh = false): array
    {
        return $this->get('metadata.exchanges', '/equity/metadata/exchanges', fresh: $fresh);
    }

    /**
     * Search the cached instrument list by ticker, name, short name or ISIN.
     *
     * @return list<array<string, mixed>>
     */
    public function searchInstruments(string $term, int $limit = 25): array
    {
        $term = mb_strtolower(trim($term));

        if ($term === '') {
            return [];
        }

        return collect($this->instruments())
            ->filter(fn (array $instrument): bool => collect(['ticker', 'shortName', 'name', 'isin'])
                ->contains(fn (string $field): bool => str_contains(
                    mb_strtolower((string) ($instrument[$field] ?? '')),
                    $term,
                )))
            ->sortBy(fn (array $instrument): int => match (true) {
                mb_strtolower((string) ($instrument['shortName'] ?? '')) === $term => 0,
                str_starts_with(mb_strtolower((string) ($instrument['ticker'] ?? '')), $term) => 1,
                default => 2,
            })
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextPagePath: string|null}
     */
    public function orderHistory(?string $cursor = null, ?string $ticker = null, int $limit = 20): array
    {
        return $this->historyPage('history.orders', '/equity/history/orders', $this->historyQuery($cursor, $ticker, $limit));
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextPagePath: string|null}
     */
    public function dividends(?string $cursor = null, ?string $ticker = null, int $limit = 20): array
    {
        return $this->historyPage(
            'history.dividends',
            '/equity/history/dividends',
            $this->historyQuery($cursor, $ticker, $limit),
        );
    }

    /**
     * @return array{items: list<array<string, mixed>>, nextPagePath: string|null}
     */
    public function transactions(?string $cursor = null, int $limit = 20): array
    {
        return $this->historyPage(
            'history.transactions',
            '/equity/history/transactions',
            $this->historyQuery($cursor, null, $limit),
        );
    }

    /**
     * Fetch a paginated history endpoint and guarantee the page shape, so callers never
     * have to guard against a response missing `items` or `nextPagePath`.
     *
     * @param  array<string, scalar>  $query
     * @return array{items: list<array<string, mixed>>, nextPagePath: string|null}
     */
    private function historyPage(string $endpoint, string $path, array $query): array
    {
        $page = $this->get($endpoint, $path, $query);
        $items = is_array($page['items'] ?? null) ? $page['items'] : [];
        $nextPagePath = $page['nextPagePath'] ?? null;

        return [
            'items' => array_values(array_filter($items, is_array(...))),
            'nextPagePath' => is_string($nextPagePath) ? $nextPagePath : null,
        ];
    }

    /**
     * Seconds until the endpoint can be called again (0 = now). Useful for UI hints.
     */
    public function availableIn(string $endpoint): int
    {
        return $this->rateLimiter->availableIn($this->account, $endpoint);
    }

    public function flushTradingState(): void
    {
        foreach (self::TRADING_STATE_ENDPOINTS as $endpoint) {
            $this->cache->forget($this->cacheKey($endpoint, []));
        }
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<mixed>
     */
    private function get(string $endpoint, string $path, array $query = [], bool $fresh = false): array
    {
        $ttl = $this->rateLimiter->definition($endpoint)['cache_ttl'];
        $key = $this->cacheKey($endpoint, $query);

        $cached = ! $fresh && $ttl > 0 ? $this->cache->get($key) : null;

        if (is_array($cached)) {
            return $cached;
        }

        $data = $this->send('get', $endpoint, $path, $query)->json() ?? [];

        if ($ttl > 0) {
            $this->cache->put($key, $data, $ttl);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws Trading212Exception
     * @throws RateLimitExceededException
     */
    private function send(string $method, string $endpoint, string $path, array $data = []): Response
    {
        $attempt = 0;

        do {
            $attempt++;

            // On a retry this waits for the block set by the previous 429 (or throws when it exceeds max_wait_seconds).
            $this->rateLimiter->acquire($this->account, $endpoint);

            try {
                $response = $this->request()->{$method}($path, $data);
            } catch (ConnectionException $e) {
                throw new Trading212Exception("Could not reach Trading 212: {$e->getMessage()}");
            }

            $this->rateLimiter->recordResponse($this->account, $endpoint, $response);
        } while ($response->status() === 429 && $attempt < 2);

        if ($response->status() === 429) {
            throw new RateLimitExceededException(
                $endpoint,
                max(1, $this->rateLimiter->availableIn($this->account, $endpoint)),
            );
        }

        if ($response->failed()) {
            throw Trading212Exception::fromResponse($endpoint, $response);
        }

        return $response;
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->account->environment->baseUrl())
            ->withBasicAuth($this->account->api_key, $this->account->api_secret)
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('trading212.timeout'));
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function cacheKey(string $endpoint, array $query): string
    {
        ksort($query);

        return "t212:resp:{$this->account->credential_fingerprint}:{$endpoint}:".md5(http_build_query($query));
    }

    /**
     * @return array<string, scalar>
     */
    private function historyQuery(?string $cursor, ?string $ticker, int $limit): array
    {
        return array_filter([
            'cursor' => $cursor,
            'ticker' => $ticker,
            'limit' => min(50, max(1, $limit)),
        ], fn ($value) => $value !== null);
    }
}
