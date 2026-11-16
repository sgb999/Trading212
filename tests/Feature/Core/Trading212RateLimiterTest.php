<?php

use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Trading212RateLimiter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->freezeTime();
});

test('a request within the endpoint limit is allowed and the next one is refused', function () {
    $account = TradingAccount::factory()->create();
    $limiter = app(Trading212RateLimiter::class);

    $limiter->acquire($account, 'account.summary', maxWait: 0);

    expect(fn () => $limiter->acquire($account, 'account.summary', maxWait: 0))
        ->toThrow(RateLimitExceededException::class);
});

test('endpoints with a burst limit allow that many requests per window', function () {
    $account = TradingAccount::factory()->create();
    $limiter = app(Trading212RateLimiter::class);

    foreach (range(1, 20) as $request) {
        $limiter->acquire($account, 'history.orders', maxWait: 0);
    }

    expect(fn () => $limiter->acquire($account, 'history.orders', maxWait: 0))
        ->toThrow(RateLimitExceededException::class);
});

test('each endpoint has its own budget', function () {
    $account = TradingAccount::factory()->create();
    $limiter = app(Trading212RateLimiter::class);

    $limiter->acquire($account, 'account.summary', maxWait: 0);
    $limiter->acquire($account, 'positions', maxWait: 0);
    $limiter->acquire($account, 'orders.list', maxWait: 0);

    expect($limiter->availableIn($account, 'account.summary'))->toBeGreaterThan(0)
        ->and($limiter->availableIn($account, 'orders.show'))->toBe(0);
});

test('two local accounts with the same credentials share one budget', function () {
    $first = TradingAccount::factory()->create(['api_key' => 'shared-key']);
    $second = TradingAccount::factory()->create(['api_key' => 'shared-key']);
    $other = TradingAccount::factory()->create(['api_key' => 'other-key']);
    $limiter = app(Trading212RateLimiter::class);

    $limiter->acquire($first, 'account.summary', maxWait: 0);

    expect(fn () => $limiter->acquire($second, 'account.summary', maxWait: 0))
        ->toThrow(RateLimitExceededException::class);

    $limiter->acquire($other, 'account.summary', maxWait: 0);
});

test('the same key on demo and live has separate budgets', function () {
    $demo = TradingAccount::factory()->create(['api_key' => 'shared-key']);
    $live = TradingAccount::factory()->live()->create(['api_key' => 'shared-key']);
    $limiter = app(Trading212RateLimiter::class);

    $limiter->acquire($demo, 'account.summary', maxWait: 0);
    $limiter->acquire($live, 'account.summary', maxWait: 0);

    expect($demo->credential_fingerprint)->not->toBe($live->credential_fingerprint);
});

test('changing the environment of an account recomputes its credential fingerprint', function () {
    $account = TradingAccount::factory()->create(['api_key' => 'shared-key']);
    $demoFingerprint = $account->credential_fingerprint;

    $account->update(['environment' => 'live']);

    expect($account->credential_fingerprint)->not->toBe($demoFingerprint);
});

test('it waits for the window to reset and then succeeds', function () {
    Sleep::fake();
    Sleep::syncWithCarbon();
    $account = TradingAccount::factory()->create();
    $limiter = app(Trading212RateLimiter::class);

    $limiter->acquire($account, 'account.summary');
    $limiter->acquire($account, 'account.summary');

    Sleep::assertSequence([Sleep::for(6)->seconds()]);
});

test('it throws instead of waiting longer than the maximum wait', function () {
    Sleep::fake();
    config(['trading212.max_wait_seconds' => 10]);
    $account = TradingAccount::factory()->create();
    $limiter = app(Trading212RateLimiter::class);

    $limiter->acquire($account, 'metadata.instruments');

    expect(fn () => $limiter->acquire($account, 'metadata.instruments'))
        ->toThrow(fn (RateLimitExceededException $e) => expect($e->retryAfter)->toBe(51)
            ->and($e->endpoint)->toBe('metadata.instruments'));

    Sleep::assertNeverSlept();
});

test('a window is never shorter than the documented period', function () {
    $account = TradingAccount::factory()->create();
    $limiter = app(Trading212RateLimiter::class);

    $limiter->acquire($account, 'positions', maxWait: 0);
    $this->travel(1)->seconds();

    expect(fn () => $limiter->acquire($account, 'positions', maxWait: 0))
        ->toThrow(RateLimitExceededException::class);

    $this->travel(1)->seconds();
    $limiter->acquire($account, 'positions', maxWait: 0);
});

test('x-ratelimit-remaining 0 blocks the endpoint until the reset timestamp', function () {
    $account = TradingAccount::factory()->create();
    Http::fake(['demo.trading212.com/*' => Http::response([], 200, [
        'x-ratelimit-remaining' => '0',
        'x-ratelimit-reset' => (string) (now()->getTimestamp() + 30),
    ])]);
    $limiter = app(Trading212RateLimiter::class);

    $limiter->recordResponse(
        $account,
        'history.orders',
        Http::get('https://demo.trading212.com/api/v0/equity/history/orders'),
    );

    expect($limiter->availableIn($account, 'history.orders'))->toBe(30)
        ->and(fn () => $limiter->acquire($account, 'history.orders', maxWait: 10))
        ->toThrow(fn (RateLimitExceededException $e) => expect($e->retryAfter)->toBe(30));

    $this->travel(30)->seconds();

    $limiter->acquire($account, 'history.orders', maxWait: 0);
});

test('remaining requests above zero do not block', function () {
    $account = TradingAccount::factory()->create();
    Http::fake(['demo.trading212.com/*' => Http::response([], 200, [
        'x-ratelimit-remaining' => '5',
        'x-ratelimit-reset' => (string) (now()->getTimestamp() + 30),
    ])]);
    $limiter = app(Trading212RateLimiter::class);

    $limiter->recordResponse(
        $account,
        'history.orders',
        Http::get('https://demo.trading212.com/api/v0/equity/history/orders'),
    );

    expect($limiter->availableIn($account, 'history.orders'))->toBe(0);
});

test('a 429 without a usable reset header blocks for one period', function () {
    $account = TradingAccount::factory()->create();
    Http::fake(['demo.trading212.com/*' => Http::response([], 429, [
        'x-ratelimit-reset' => (string) (now()->getTimestamp() - 5),
    ])]);
    $limiter = app(Trading212RateLimiter::class);

    $limiter->recordResponse(
        $account,
        'account.summary',
        Http::get('https://demo.trading212.com/api/v0/equity/account/summary'),
    );

    expect($limiter->availableIn($account, 'account.summary'))->toBe(5);
});

test('it works against the Postgres database cache store', function () {
    config(['trading212.cache_store' => 'database']);
    Sleep::fake();
    Sleep::syncWithCarbon();
    $account = TradingAccount::factory()->create();
    $limiter = app(Trading212RateLimiter::class);

    foreach (range(1, 50) as $request) {
        $limiter->acquire($account, 'orders.market', maxWait: 0);
    }

    expect(fn () => $limiter->acquire($account, 'orders.market', maxWait: 0))
        ->toThrow(RateLimitExceededException::class)
        ->and($limiter->availableIn($account, 'orders.market'))->toBe(61);

    $limiter->acquire($account, 'account.summary');
    $limiter->acquire($account, 'account.summary');

    Sleep::assertSequence([Sleep::for(6)->seconds()]);
    $this->assertDatabaseHas('cache', [
        'key' => config('cache.prefix').'t212:rl:'.$account->credential_fingerprint.':orders.market',
    ]);
});
