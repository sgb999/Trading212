<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\TradeOrderStatus;
use App\Exceptions\AgentNotAuthorizedException;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\OrderInProgressException;
use App\Services\Trading212\Exceptions\OrderOutcomeUnknownException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Exceptions\UnresolvedOrderException;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
    $this->freezeTime();
});

/**
 * @param  array<string, mixed>  $roleAttributes
 */
function lockedTrader(TradingAccount $account, array $roleAttributes = []): AiAgent
{
    $agent = AiAgent::factory()
        ->for(Role::factory()->trader()->create($roleAttributes))
        ->for($account->user)
        ->create();
    $agent->tradingAccounts()->attach($account);

    return $agent;
}

function lockedMarketBuy(string $ticker = 'AAPL_US_EQ'): OrderRequest
{
    return new OrderRequest(ticker: $ticker, type: OrderType::Market, side: OrderSide::Buy, quantity: 1);
}

/**
 * Takes the account's order lock as a different owner, as a concurrent process would.
 */
function holdOrderLock(TradingAccount $account): Lock
{
    $lock = Cache::store(config('trading212.cache_store'))->lock(PlaceOrder::lockKey($account), 60);

    expect($lock->get())->toBeTrue();

    return $lock;
}

/**
 * Simulates the lock's TTL expiring and another process taking it.
 */
function lapseOrderLockToAnotherProcess(TradingAccount $account): void
{
    Cache::store(config('trading212.cache_store'))->lock(PlaceOrder::lockKey($account))->forceRelease();
    holdOrderLock($account);
}

function orderLockIsFree(TradingAccount $account): bool
{
    $lock = Cache::store(config('trading212.cache_store'))->lock(PlaceOrder::lockKey($account), 60);

    if (! $lock->get()) {
        return false;
    }

    $lock->release();

    return true;
}

test('an agent order is denied without any API call while another process holds the account lock', function (?string $cacheStore) {
    config(['trading212.cache_store' => $cacheStore]);
    Http::fake();
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account);
    holdOrderLock($account);
    $startedAt = now();

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class, OrderInProgressException::message());

    Http::assertNothingSent();
    expect(TradeOrder::query()->count())->toBe(0)
        ->and($startedAt->diffInSeconds(now()))->toBeGreaterThanOrEqual(PlaceOrder::LOCK_WAIT_SECONDS - 1);
})->with([
    'default store' => [null],
    'database store' => ['database'],
]);

test('a user order is refused without any API call while another process holds the account lock', function () {
    Http::fake();
    $account = TradingAccount::factory()->create();
    holdOrderLock($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $account->user))
        ->toThrow(OrderInProgressException::class);

    Http::assertNothingSent();
});

test('the MCP place-order tool logs a held lock as a denial', function () {
    Http::fake();
    $account = TradingAccount::factory()->create();
    app(CurrentAgent::class)->set(lockedTrader($account));
    holdOrderLock($account);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'BUY',
        'quantity' => 1,
    ])->assertHasErrors();

    Http::assertNothingSent();
    $this->assertDatabaseHas(AgentActivityLog::class, [
        'tool' => 'place-order',
        'outcome' => AgentActivityLog::OUTCOME_DENIED,
        'trading_account_id' => $account->id,
    ]);
});

test('holding one account lock does not block orders on another account', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 1, 'status' => 'NEW'])]);
    $lockedAccount = TradingAccount::factory()->create();
    $otherAccount = TradingAccount::factory()->for($lockedAccount->user)->create();
    $agent = lockedTrader($otherAccount);
    holdOrderLock($lockedAccount);

    $tradeOrder = app(PlaceOrder::class)->handle($otherAccount, lockedMarketBuy(), $agent);

    expect($tradeOrder->status)->toBe(TradeOrderStatus::Submitted);
    Http::assertSentCount(1);
});

test('the lock is held while authorising and placing, and released after success', function (?string $cacheStore) {
    config(['trading212.cache_store' => $cacheStore]);
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account, ['max_order_value' => 1_000]);
    $lockWasHeld = [];
    Http::fake([
        'demo.trading212.com/api/v0/equity/metadata/instruments' => Http::response([
            ['ticker' => 'AAPL_US_EQ', 'currencyCode' => 'GBP'],
        ]),
        'demo.trading212.com/api/v0/equity/positions*' => function () use ($account, &$lockWasHeld) {
            $lockWasHeld['authorisation'] = ! orderLockIsFree($account);

            return Http::response([['instrument' => ['ticker' => 'AAPL_US_EQ'], 'currentPrice' => 100]]);
        },
        'demo.trading212.com/api/v0/equity/orders/market' => function () use ($account, &$lockWasHeld) {
            $lockWasHeld['placement'] = ! orderLockIsFree($account);

            return Http::response(['id' => 777, 'status' => 'NEW']);
        },
    ]);

    $tradeOrder = app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent);

    expect($lockWasHeld)->toBe(['authorisation' => true, 'placement' => true])
        ->and($tradeOrder->external_id)->toBe(777)
        ->and(orderLockIsFree($account))->toBeTrue();
})->with([
    'default store' => [null],
    'database store' => ['database'],
]);

test('a concurrent order arriving mid-placement is denied, so the daily limit cannot be raced', function () {
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account, ['max_orders_per_day' => 1]);
    $concurrentAttempt = null;
    Http::fake([
        'demo.trading212.com/api/v0/equity/orders/market' => function () use ($account, $agent, &$concurrentAttempt) {
            try {
                app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent);
            } catch (AgentNotAuthorizedException $e) {
                $concurrentAttempt = $e->getMessage();
            }

            return Http::response(['id' => 1, 'status' => 'NEW']);
        },
    ]);

    app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent);

    expect($concurrentAttempt)->toBe(OrderInProgressException::message())
        ->and($agent->tradeOrders()->count())->toBe(1);
    Http::assertSentCount(1);
    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Daily order limit of 1 reached.');
});

test('the lock is released after the API call fails, so the next order succeeds', function () {
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account);
    Http::fakeSequence('demo.trading212.com/api/v0/equity/orders/market')
        ->push(['message' => 'Insufficient funds'], 400)
        ->push(['id' => 2, 'status' => 'NEW']);

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent))
        ->toThrow(Trading212Exception::class);
    expect(orderLockIsFree($account))->toBeTrue();

    $tradeOrder = app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent);

    expect($tradeOrder->external_id)->toBe(2);
});

test('the lock is released after the agent is denied by its role', function () {
    Http::fake();
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account, ['allowed_tickers' => ['MSFT_US_EQ']]);

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class);

    expect(orderLockIsFree($account))->toBeTrue();
    Http::assertNothingSent();
});

test('a concurrent order on a different ticker for the same account is also denied mid-placement', function () {
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account);
    $concurrentAttempt = null;
    Http::fake([
        'demo.trading212.com/api/v0/equity/orders/market' => function () use ($account, $agent, &$concurrentAttempt) {
            try {
                app(PlaceOrder::class)->handle($account, lockedMarketBuy('MSFT_US_EQ'), $agent);
            } catch (AgentNotAuthorizedException $e) {
                $concurrentAttempt = $e->getMessage();
            }

            return Http::response(['id' => 1, 'status' => 'NEW']);
        },
    ]);

    app(PlaceOrder::class)->handle($account, lockedMarketBuy('AAPL_US_EQ'), $agent);

    expect($concurrentAttempt)->toBe(OrderInProgressException::message())
        ->and($account->tradeOrders()->pluck('ticker')->all())->toBe(['AAPL_US_EQ']);
    Http::assertSentCount(1);
});

test('an agent order whose lock lapses during authorisation is refused before it is saved or sent', function () {
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account, ['max_order_value' => 1_000]);
    Http::fake([
        'demo.trading212.com/api/v0/equity/metadata/instruments' => Http::response([
            ['ticker' => 'AAPL_US_EQ', 'currencyCode' => 'GBP'],
        ]),
        'demo.trading212.com/api/v0/equity/positions*' => function () use ($account) {
            lapseOrderLockToAnotherProcess($account);

            return Http::response([['instrument' => ['ticker' => 'AAPL_US_EQ'], 'currentPrice' => 100]]);
        },
        'demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 1, 'status' => 'NEW']),
    ]);

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class, OrderInProgressException::lockLapsedMessage());

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'orders/market'));
    expect(TradeOrder::query()->count())->toBe(0)
        ->and(orderLockIsFree($account))->toBeFalse();
});

test('a same-ticker order is still refused when the lock lapses during the placement request', function () {
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account);
    $concurrentAttempt = null;
    Http::fake([
        'demo.trading212.com/api/v0/equity/orders/market' => function () use ($account, $agent, &$concurrentAttempt) {
            Cache::store(config('trading212.cache_store'))->lock(PlaceOrder::lockKey($account))->forceRelease();

            try {
                app(PlaceOrder::class)->handle($account, lockedMarketBuy('aapl_us_eq'), $agent);
            } catch (AgentNotAuthorizedException $e) {
                $concurrentAttempt = $e->getMessage();
            }

            return Http::response(['id' => 1, 'status' => 'NEW']);
        },
    ]);

    $tradeOrder = app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent);

    expect($concurrentAttempt)->toBe(UnresolvedOrderException::messageFor('aapl_us_eq'))
        ->and($tradeOrder->status)->toBe(TradeOrderStatus::Submitted)
        ->and($account->tradeOrders()->count())->toBe(1);
    Http::assertSentCount(1);
});

test('the lock is released after the outcome is left unknown, so the next order on another ticker succeeds', function (Closure $failure) {
    $account = TradingAccount::factory()->create();
    $agent = lockedTrader($account);
    Http::fake([
        'demo.trading212.com/api/v0/equity/orders/market' => function ($request) use ($failure) {
            return $request['ticker'] === 'AAPL_US_EQ' ? $failure() : Http::response(['id' => 3, 'status' => 'NEW']);
        },
    ]);

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $agent))
        ->toThrow(OrderOutcomeUnknownException::class);
    expect(orderLockIsFree($account))->toBeTrue();

    $tradeOrder = app(PlaceOrder::class)->handle($account, lockedMarketBuy('MSFT_US_EQ'), $agent);

    expect($tradeOrder->external_id)->toBe(3);
})->with([
    'server error' => [fn () => fn () => Http::response(['message' => 'Bad gateway'], 502)],
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
]);

test('the lock is released after an order is refused for an unresolved ticker', function () {
    Http::fake();
    $account = TradingAccount::factory()->create();
    TradeOrder::factory()->for($account)->create(['ticker' => 'AAPL_US_EQ', 'status' => TradeOrderStatus::Unknown]);

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $account->user))
        ->toThrow(UnresolvedOrderException::class);

    expect(orderLockIsFree($account))->toBeTrue();
    Http::assertNothingSent();
});

test('a user order whose lock lapses during the unresolved-ticker check is refused before it is saved or sent', function () {
    Http::fake();
    $account = TradingAccount::factory()->create();
    $hasLapsed = false;
    DB::listen(function ($query) use ($account, &$hasLapsed): void {
        if (! $hasLapsed && str_contains($query->sql, 'trade_orders')) {
            $hasLapsed = true;
            lapseOrderLockToAnotherProcess($account);
        }
    });

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $account->user))
        ->toThrow(OrderInProgressException::class, OrderInProgressException::lockLapsedMessage());

    Http::assertNothingSent();
    expect($hasLapsed)->toBeTrue()
        ->and(TradeOrder::query()->count())->toBe(0);
});

test('outside the testing environment an order is refused when the lock store is not shared', function (string $cacheStore) {
    Http::fake();
    app()->detectEnvironment(fn (): string => 'production');
    config(['cache.stores.null' => ['driver' => 'null'], 'trading212.cache_store' => $cacheStore]);
    $account = TradingAccount::factory()->create();

    expect(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), lockedTrader($account)))
        ->toThrow(LogicException::class, 'is not shared between processes');

    Http::assertNothingSent();
    expect(TradeOrder::query()->count())->toBe(0);
})->with([
    'array store' => ['array'],
    'null store' => ['null'],
    'failover store falling back to array' => ['failover'],
]);

test('outside the testing environment an order is placed when the lock store is shared', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 4, 'status' => 'NEW'])]);
    app()->detectEnvironment(fn (): string => 'production');
    config(['trading212.cache_store' => 'database']);
    $account = TradingAccount::factory()->create();

    $tradeOrder = app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $account->user);

    expect($tradeOrder->external_id)->toBe(4);
});

test('an order placed inside a database transaction is refused before anything is sent', function () {
    Http::fake();
    $account = TradingAccount::factory()->create();

    expect(fn () => DB::transaction(fn () => app(PlaceOrder::class)->handle($account, lockedMarketBuy(), $account->user)))
        ->toThrow(LogicException::class, 'inside a database transaction');

    Http::assertNothingSent();
    expect(TradeOrder::query()->count())->toBe(0)
        ->and(orderLockIsFree($account))->toBeTrue();
});

test('the lock lifetime is derived from the configured API budget', function () {
    config([
        'trading212.timeout' => 15,
        'trading212.max_wait_seconds' => 10,
        'trading212.order_lock.max_api_calls' => 6,
        'trading212.order_lock.margin_seconds' => 60,
    ]);

    expect(PlaceOrder::lockTtlSeconds())->toBe(6 * 2 * (10 + 15) + 60);

    config(['trading212.timeout' => 30, 'trading212.order_lock.max_api_calls' => 3]);

    expect(PlaceOrder::lockTtlSeconds())->toBe(3 * 2 * (10 + 30) + 60);
});

test('the lock lifetime never drops below one attempt plus a margin when the budget is zeroed', function () {
    config([
        'trading212.timeout' => 15,
        'trading212.max_wait_seconds' => 10,
        'trading212.order_lock.max_api_calls' => 0,
        'trading212.order_lock.margin_seconds' => 0,
    ]);

    expect(PlaceOrder::lockTtlSeconds())->toBe(10 + 15 + 30);

    config(['trading212.timeout' => 0, 'trading212.max_wait_seconds' => 0]);

    expect(PlaceOrder::lockTtlSeconds())->toBe(30);
});
