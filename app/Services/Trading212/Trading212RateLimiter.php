<?php

namespace App\Services\Trading212;

use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

/**
 * Enforces Trading 212's per-account, per-endpoint rate limits using the shared cache,
 * so every web request, queue worker and AI agent shares the same budget.
 *
 * Two layers are applied:
 *  1. A local counter per endpoint mirroring the documented limits (config/trading212.php).
 *  2. Server feedback: `x-ratelimit-remaining` / `x-ratelimit-reset` headers and 429 responses
 *     set a hard block until Trading 212 says the window resets.
 */
class Trading212RateLimiter
{
    private readonly RateLimiter $limiter;

    private readonly Repository $cache;

    public function __construct()
    {
        $this->cache = Cache::store(config('trading212.cache_store'));
        $this->limiter = new RateLimiter($this->cache);
    }

    /**
     * Reserve a request slot, waiting up to `$maxWait` seconds. Throws if no slot frees up in time.
     *
     * @throws RateLimitExceededException
     */
    public function acquire(TradingAccount $account, string $endpoint, ?int $maxWait = null): void
    {
        ['limit' => $limit, 'period' => $period] = $this->definition($endpoint);
        $decaySeconds = $this->decaySeconds($period);
        $maxWait ??= (int) config('trading212.max_wait_seconds');
        $waited = 0;

        while (true) {
            $wait = $this->blockedFor($account, $endpoint);

            if ($wait === 0) {
                $key = $this->counterKey($account, $endpoint);

                if ($this->limiter->hit($key, $decaySeconds) <= $limit) {
                    return;
                }

                $this->limiter->decrement($key, $decaySeconds);
                $wait = max(1, $this->limiter->availableIn($key));
            }

            if ($waited + $wait > $maxWait) {
                throw new RateLimitExceededException($endpoint, $wait);
            }

            Sleep::for($wait)->seconds();
            $waited += $wait;
        }
    }

    /**
     * Seconds until a request to the endpoint could be made (0 = available now). Does not reserve.
     */
    public function availableIn(TradingAccount $account, string $endpoint): int
    {
        ['limit' => $limit] = $this->definition($endpoint);
        $blocked = $this->blockedFor($account, $endpoint);

        if ($blocked > 0) {
            return $blocked;
        }

        $key = $this->counterKey($account, $endpoint);

        return $this->limiter->attempts($key) >= $limit ? max(1, $this->limiter->availableIn($key)) : 0;
    }

    /**
     * Record Trading 212's rate limit headers, blocking the endpoint when the server says we're out.
     */
    public function recordResponse(TradingAccount $account, string $endpoint, Response $response): void
    {
        $remaining = $response->header('x-ratelimit-remaining');
        $reset = (int) $response->header('x-ratelimit-reset');

        if ($response->status() === 429 || ($remaining !== '' && (int) $remaining <= 0)) {
            $now = Carbon::now()->getTimestamp();

            $resetAt = $reset > $now
                ? $reset
                : $now + $this->definition($endpoint)['period'];

            $this->blockUntil($account, $endpoint, $resetAt);
        }
    }

    public function blockUntil(TradingAccount $account, string $endpoint, int $resetTimestamp): void
    {
        $ttl = $resetTimestamp - Carbon::now()->getTimestamp();

        if ($ttl > 0) {
            $this->cache->put($this->blockKey($account, $endpoint), $resetTimestamp, $ttl);
        }
    }

    public function clear(TradingAccount $account, string $endpoint): void
    {
        $this->limiter->clear($this->counterKey($account, $endpoint));
        $this->cache->forget($this->blockKey($account, $endpoint));
    }

    /**
     * @return array{limit: int, period: int, cache_ttl: int}
     */
    public function definition(string $endpoint): array
    {
        $definition = config('trading212.endpoints')[$endpoint] ?? null;

        if (! is_array($definition)) {
            throw new InvalidArgumentException("Unknown Trading 212 endpoint [{$endpoint}].");
        }

        return $definition;
    }

    /**
     * Cache stores such as `database` only track whole-second expirations, so a window opened at
     * 10.9s with a 1s period would already expire at 11.0s. One extra second guarantees each local
     * window lasts at least the documented period.
     */
    private function decaySeconds(int $period): int
    {
        return $period + 1;
    }

    private function blockedFor(TradingAccount $account, string $endpoint): int
    {
        $until = (int) $this->cache->get($this->blockKey($account, $endpoint), 0);

        return max(0, $until - Carbon::now()->getTimestamp());
    }

    /**
     * Limits are per Trading 212 account, so key on the credential fingerprint: two local records
     * using the same key share one budget.
     */
    private function counterKey(TradingAccount $account, string $endpoint): string
    {
        return "t212:rl:{$account->credential_fingerprint}:{$endpoint}";
    }

    private function blockKey(TradingAccount $account, string $endpoint): string
    {
        return "t212:block:{$account->credential_fingerprint}:{$endpoint}";
    }
}
