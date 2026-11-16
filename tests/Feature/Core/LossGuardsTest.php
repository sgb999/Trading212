<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TimeValidity;
use App\Enums\TradeOrderStatus;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\PortfolioSnapshot;
use App\Models\Role;
use App\Models\TradingAccount;
use App\Services\Trading212\Trading212RateLimiter;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const LOSS_GUARD_EXTERNAL_ID = 4_242_424;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-11-16 11:00', 'Europe/London')->utc());
    $this->account = TradingAccount::factory()->create(['external_account_id' => LOSS_GUARD_EXTERNAL_ID]);
});

function lossGuardAgent(TradingAccount $account, ?float $maxDailyLossPercent, ?float $minPortfolioValue = null): AiAgent
{
    $role = Role::factory()
        ->withPermissions(Permission::cases())
        ->withLossGuards($maxDailyLossPercent, $minPortfolioValue)
        ->create();

    $agent = AiAgent::factory()->for($role)->for($account->user)->create();
    $agent->tradingAccounts()->attach($account);

    return $agent;
}

function lossGuardSnapshot(TradingAccount $account, float $totalValue, string $takenAt, string $currency = 'GBP'): PortfolioSnapshot
{
    return PortfolioSnapshot::factory()->for($account)->create([
        'external_account_id' => LOSS_GUARD_EXTERNAL_ID,
        'total_value' => $totalValue,
        'cash' => $totalValue,
        'invested' => 0,
        'currency' => $currency,
        'taken_at' => Carbon::parse($takenAt, 'Europe/London')->utc(),
    ]);
}

/**
 * Fakes the account summary (current value) and the order endpoints.
 *
 * @param  array<string, mixed>  $overrides
 */
function lossGuardFakeTrading212(float $totalValue, array $overrides = []): void
{
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response([
            'id' => LOSS_GUARD_EXTERNAL_ID,
            'currency' => 'GBP',
            'totalValue' => $totalValue,
            'cash' => ['availableToTrade' => $totalValue, 'reservedForOrders' => 0, 'inPies' => 0],
            'investments' => ['currentValue' => 0],
            ...$overrides,
        ]),
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
    ]);
}

function lossGuardOrder(OrderSide $side = OrderSide::Buy): OrderRequest
{
    return new OrderRequest(ticker: 'VUSA_EQ', type: OrderType::Limit, side: $side, quantity: 1, limitPrice: 10);
}

function lossGuardPlace(
    TradingAccount $account,
    AiAgent $agent,
    OrderSide $side = OrderSide::Buy,
    ?OrderRequest $order = null,
): TradeOrderStatus {
    return app(PlaceOrder::class)->handle($account, $order ?? lossGuardOrder($side), $agent)->status;
}

function lossGuardSummaryRequests(): int
{
    return Http::recorded(fn (HttpRequest $request): bool => str_contains($request->url(), 'account/summary'))->count();
}

function lossGuardAssertNoOrderSent(): void
{
    Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'POST');
}

test('a buy is denied when a guard is set and there is no snapshot today', function (?float $maxLoss, ?float $minValue) {
    lossGuardFakeTrading212(200);
    lossGuardSnapshot($this->account, 200, '2026-11-13 16:40');
    $agent = lossGuardAgent($this->account, $maxLoss, $minValue);

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'no portfolio snapshot for today (Europe/London)');

    lossGuardAssertNoOrderSent();
})->with([
    'max daily loss' => [5, null],
    'min portfolio value' => [null, 100],
]);

test('a buy is denied when the drawdown exceeds the limit', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(189.50);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))->toThrow(
        AgentNotAuthorizedException::class,
        "Portfolio value £189.50 is down 5.25% from today's reference of £200.00 (max daily loss 5.00%, floor £190.00)",
    );

    lossGuardAssertNoOrderSent();
});

test('a buy is allowed at or below the drawdown limit', function (float $currentValue) {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212($currentValue);
    $agent = lossGuardAgent($this->account, 5);

    expect(lossGuardPlace($this->account, $agent))->toBe(TradeOrderStatus::Submitted);
})->with([
    'exactly at the limit' => [190.00],
    'under the limit' => [195.50],
    'up on the day' => [220.00],
]);

test('the reference is the previous day last snapshot when it is higher than the first snapshot today', function () {
    lossGuardSnapshot($this->account, 210, '2026-11-13 16:40');
    lossGuardSnapshot($this->account, 200, '2026-11-16 10:30');
    lossGuardFakeTrading212(195);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))->toThrow(
        AgentNotAuthorizedException::class,
        "down 7.14% from today's reference of £210.00 (max daily loss 5.00%, floor £199.50)",
    );

    lossGuardAssertNoOrderSent();
});

test('the reference is the first snapshot today when it is higher than the previous day', function () {
    lossGuardSnapshot($this->account, 180, '2026-11-13 16:40');
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(185);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'reference of £200.00');

    lossGuardAssertNoOrderSent();
});

test('snapshots later today do not move the reference', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardSnapshot($this->account, 185, '2026-11-16 10:45');
    lossGuardFakeTrading212(185);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'reference of £200.00');

    lossGuardAssertNoOrderSent();
});

test('a buy is denied below the min portfolio value', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(149.99);
    $agent = lossGuardAgent($this->account, null, 150);

    expect(fn () => lossGuardPlace($this->account, $agent))->toThrow(
        AgentNotAuthorizedException::class,
        "Portfolio value £149.99 is below the role's minimum of £150.00",
    );

    lossGuardAssertNoOrderSent();
});

test('a buy is allowed at the min portfolio value', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(150);
    $agent = lossGuardAgent($this->account, null, 150);

    expect(lossGuardPlace($this->account, $agent))->toBe(TradeOrderStatus::Submitted);
});

test('a null max daily loss disables the drawdown guard', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(120);
    $agent = lossGuardAgent($this->account, null, 100);

    expect(lossGuardPlace($this->account, $agent))->toBe(TradeOrderStatus::Submitted);
});

test('with both guards null a buy needs no snapshot and fetches no summary', function () {
    lossGuardFakeTrading212(200);
    $agent = lossGuardAgent($this->account, null);

    expect(lossGuardPlace($this->account, $agent))->toBe(TradeOrderStatus::Submitted);

    Http::assertNotSent(fn (HttpRequest $request): bool => str_contains($request->url(), 'account/summary'));
});

test('a sell is never blocked by the loss guards', function (string $scenario) {
    if ($scenario === 'drawdown') {
        lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
        lossGuardFakeTrading212(100);
    } else {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW'])]);
    }
    $agent = lossGuardAgent($this->account, 5, 150);

    expect(lossGuardPlace($this->account, $agent, OrderSide::Sell))->toBe(TradeOrderStatus::Submitted);
})->with(['drawdown', 'no snapshot today']);

test('a buy is denied when the current value cannot be fetched', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response(['code' => 'Error'], 500)]);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'current portfolio value could not be determined');

    lossGuardAssertNoOrderSent();
});

test('a buy is denied when the current summary is unusable', function (array $overrides, string $message) {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(200, $overrides);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))->toThrow(AgentNotAuthorizedException::class, $message);

    lossGuardAssertNoOrderSent();
})->with([
    'missing total' => [['totalValue' => null], 'invalid totalValue'],
    'zero total' => [['totalValue' => 0], 'invalid totalValue'],
    'non-numeric total' => [['totalValue' => 'lots'], 'invalid totalValue'],
    'non-GBP summary' => [['currency' => 'EUR'], 'the account summary is in [EUR]'],
    'other Trading 212 account' => [['id' => 999], 'different Trading 212 account'],
    'missing account id' => [['id' => null], 'different Trading 212 account'],
]);

test('a buy is denied when a reference snapshot is not in GBP', function (string $staleCurrency, string $todayCurrency) {
    lossGuardSnapshot($this->account, 200, '2026-11-13 16:40', $staleCurrency);
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05', $todayCurrency);
    lossGuardFakeTrading212(200);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'the portfolio snapshot is in [USD]');

    lossGuardAssertNoOrderSent();
})->with([
    'today' => ['GBP', 'USD'],
    'previous day' => ['USD', 'GBP'],
]);

test('snapshots of another Trading 212 account are ignored', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05')->update(['external_account_id' => 1]);
    lossGuardFakeTrading212(200);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'no portfolio snapshot for today');

    lossGuardAssertNoOrderSent();
});

test('today is the Europe/London day, not the UTC day', function () {
    $agent = lossGuardAgent($this->account, 5);
    lossGuardFakeTrading212(200);

    // 23:45 BST on 14 July is the same UTC day (14 July) as 00:30 BST on 15 July, but yesterday in London.
    $this->travelTo(Carbon::parse('2026-07-15 00:30', 'Europe/London')->utc());
    lossGuardSnapshot($this->account, 200, '2026-07-14 23:45');

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'no portfolio snapshot for today');
    lossGuardAssertNoOrderSent();

    // 00:15 BST on 16 July is still 15 July in UTC, but it is today in London at 08:00 BST on 16 July.
    $this->travelTo(Carbon::parse('2026-07-16 08:00', 'Europe/London')->utc());
    lossGuardSnapshot($this->account, 200, '2026-07-16 00:15');

    expect(lossGuardPlace($this->account, $agent))->toBe(TradeOrderStatus::Submitted);
});

test('a non-day buy is denied when a guard is set', function (OrderType $type, ?float $maxLoss, ?float $minValue) {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(200);
    $agent = lossGuardAgent($this->account, $maxLoss, $minValue);
    $order = new OrderRequest(
        ticker: 'VUSA_EQ',
        type: $type,
        side: OrderSide::Buy,
        quantity: 1,
        limitPrice: $type->requiresLimitPrice() ? 10 : null,
        stopPrice: $type->requiresStopPrice() ? 10 : null,
        timeValidity: TimeValidity::GoodTillCancel,
    );

    expect(fn () => lossGuardPlace($this->account, $agent, order: $order))
        ->toThrow(AgentNotAuthorizedException::class, 'BUY orders must use DAY time validity');

    lossGuardAssertNoOrderSent();
    expect(lossGuardSummaryRequests())->toBe(0);
})->with([
    'limit' => OrderType::Limit,
    'stop' => OrderType::Stop,
    'stop limit' => OrderType::StopLimit,
])->with([
    'max daily loss' => [5, null],
    'min portfolio value' => [null, 100],
]);

test('a buy with no time validity defaults to day and is sent as day', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(200);
    $agent = lossGuardAgent($this->account, 5);
    $order = OrderRequest::fromArray([
        'ticker' => 'VUSA_EQ', 'type' => 'LIMIT', 'side' => 'BUY', 'quantity' => 1, 'limit_price' => 10,
    ]);

    expect(lossGuardPlace($this->account, $agent, order: $order))->toBe(TradeOrderStatus::Submitted);

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request['timeValidity'] === TimeValidity::Day->value);
});

test('the day-only rule leaves market buys, sells and unguarded roles alone', function (string $scenario) {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(200);
    $agent = lossGuardAgent($this->account, $scenario === 'unguarded role' ? null : 5);
    $order = new OrderRequest(
        ticker: 'VUSA_EQ',
        type: $scenario === 'market buy' ? OrderType::Market : OrderType::Limit,
        side: $scenario === 'sell' ? OrderSide::Sell : OrderSide::Buy,
        quantity: 1,
        limitPrice: $scenario === 'market buy' ? null : 10,
        timeValidity: TimeValidity::GoodTillCancel,
    );

    expect(lossGuardPlace($this->account, $agent, order: $order))->toBe(TradeOrderStatus::Submitted);
})->with(['market buy', 'sell', 'unguarded role']);

test('the snapshot checks run before the max value check calls the api', function () {
    lossGuardFakeTrading212(200);
    $role = Role::factory()
        ->withPermissions(Permission::cases())
        ->withLossGuards(5, null)
        ->create(['max_order_value' => 100]);
    $agent = AiAgent::factory()->for($role)->for($this->account->user)->create();
    $agent->tradingAccounts()->attach($this->account);
    $order = new OrderRequest(ticker: 'VUSA_EQ', type: OrderType::Market, side: OrderSide::Buy, quantity: 1);

    expect(fn () => lossGuardPlace($this->account, $agent, order: $order))
        ->toThrow(AgentNotAuthorizedException::class, 'no portfolio snapshot for today');

    Http::assertNothingSent();
});

test('a summary id sent as a digit string is accepted', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(200, ['id' => (string) LOSS_GUARD_EXTERNAL_ID]);
    $agent = lossGuardAgent($this->account, 5);

    expect(lossGuardPlace($this->account, $agent))->toBe(TradeOrderStatus::Submitted);
});

test('a buy is denied when the current total does not match cash plus investments', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(200, [
        'cash' => ['availableToTrade' => 50, 'reservedForOrders' => 0, 'inPies' => 0],
        'investments' => ['currentValue' => 50],
    ]);
    $agent = lossGuardAgent($this->account, 5);

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'totalValue does not match cash + investments');

    lossGuardAssertNoOrderSent();
});

test('a stale cached summary is ignored and a fresh one is fetched', function () {
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    $summary = fn (float $totalValue): array => [
        'id' => LOSS_GUARD_EXTERNAL_ID,
        'currency' => 'GBP',
        'totalValue' => $totalValue,
        'cash' => ['availableToTrade' => $totalValue, 'reservedForOrders' => 0, 'inPies' => 0],
        'investments' => ['currentValue' => 0],
    ];
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::sequence()
            ->push($summary(200))
            ->push($summary(150)),
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
    ]);
    $agent = lossGuardAgent($this->account, 5);

    expect($this->account->client()->accountSummary()['totalValue'])->toBe(200);
    app(Trading212RateLimiter::class)->clear($this->account, 'account.summary');

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Portfolio value £150.00 is down 25.00%');

    expect(lossGuardSummaryRequests())->toBe(2);
    lossGuardAssertNoOrderSent();
});

test('a buy is denied when the summary call is rate limited', function () {
    config(['trading212.max_wait_seconds' => 0]);
    lossGuardSnapshot($this->account, 200, '2026-11-16 08:05');
    lossGuardFakeTrading212(200);
    $agent = lossGuardAgent($this->account, 5);
    app(Trading212RateLimiter::class)->acquire($this->account, 'account.summary', maxWait: 0);

    expect(fn () => lossGuardPlace($this->account, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Trading 212 rate limit reached for [account.summary]');

    Http::assertNothingSent();
});
