<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TradeOrderStatus;
use App\Events\OrderPlaced;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\Trading212Exception;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * @param  list<Permission>|null  $permissions
 * @param  array<string, mixed>  $roleAttributes
 */
function coreAgentFor(
    TradingAccount $account,
    ?array $permissions = null,
    array $roleAttributes = [],
    bool $active = true,
): AiAgent {
    $role = Role::factory()
        ->withPermissions($permissions ?? Permission::cases())
        ->create($roleAttributes);

    $agent = AiAgent::factory()->for($role)->for($account->user)->create(['is_active' => $active]);
    $agent->tradingAccounts()->attach($account);

    return $agent;
}

function coreMarketBuy(float $quantity = 1, string $ticker = 'AAPL_US_EQ'): OrderRequest
{
    return new OrderRequest(ticker: $ticker, type: OrderType::Market, side: OrderSide::Buy, quantity: $quantity);
}

function coreFakeOrderEndpoints(): void
{
    Http::fake([
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
        'live.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 556, 'status' => 'FILLED']),
    ]);
}

test('a user places an order and an audit record is stored', function () {
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    Event::fake([OrderPlaced::class]);

    $tradeOrder = app(PlaceOrder::class)->handle($account, coreMarketBuy(2), $account->user);

    expect($tradeOrder->refresh())
        ->status->toBe(TradeOrderStatus::Submitted)
        ->external_id->toBe(555)
        ->external_status->toBe('NEW')
        ->quantity->toBe('2.00000000')
        ->time_validity->toBeNull()
        ->and($tradeOrder->placedBy->is($account->user))->toBeTrue();
    Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event) => $event->tradeOrder->is($tradeOrder));
});

test('users are not restricted by any role limits, including on live accounts', function () {
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->live()->create();

    $tradeOrder = app(PlaceOrder::class)->handle($account, coreMarketBuy(10_000, 'ANY_TICKER'), $account->user);

    expect($tradeOrder->status)->toBe(TradeOrderStatus::Filled);
});

test('a sell order is sent with a negative quantity', function () {
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();

    app(PlaceOrder::class)->handle(
        $account,
        new OrderRequest(ticker: 'AAPL_US_EQ', type: OrderType::Market, side: OrderSide::Sell, quantity: 3),
        $account->user,
    );

    Http::assertSent(fn ($request) => $request['quantity'] === -3.0);
});

test('a failed API call records a failed order and rethrows', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/orders/market' => Http::response(['message' => 'Insufficient funds'], 400),
    ]);
    $account = TradingAccount::factory()->create();
    Event::fake([OrderPlaced::class]);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(), $account->user))
        ->toThrow(Trading212Exception::class);

    $tradeOrder = TradeOrder::query()->sole();
    expect($tradeOrder)
        ->status->toBe(TradeOrderStatus::Failed)
        ->error->toContain('Insufficient funds')
        ->response->toBe(['message' => 'Insufficient funds']);
    Event::assertNotDispatched(OrderPlaced::class);
});

test('an agent with full permissions places an order recorded against the agent', function () {
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account);

    $tradeOrder = app(PlaceOrder::class)->handle($account, coreMarketBuy(), $agent);

    expect($tradeOrder->placedBy->is($agent))->toBeTrue();
});

test('an agent needs the permission for the order type', function (OrderRequest $order, Permission $missing) {
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, array_values(array_filter(
        Permission::cases(),
        fn (Permission $p) => $p !== $missing,
    )));

    expect(fn () => app(PlaceOrder::class)->handle($account, $order, $agent))
        ->toThrow(AgentNotAuthorizedException::class);

    Http::assertNothingSent();
    expect(TradeOrder::query()->count())->toBe(0);
})->with([
    'market' => [fn () => coreMarketBuy(), Permission::PlaceMarketOrders],
    'limit' => [
        fn () => new OrderRequest('AAPL_US_EQ', OrderType::Limit, OrderSide::Buy, 1, limitPrice: 10),
        Permission::PlaceLimitOrders,
    ],
    'stop' => [
        fn () => new OrderRequest('AAPL_US_EQ', OrderType::Stop, OrderSide::Buy, 1, stopPrice: 10),
        Permission::PlaceStopOrders,
    ],
    'stop limit' => [
        fn () => new OrderRequest('AAPL_US_EQ', OrderType::StopLimit, OrderSide::Buy, 1, limitPrice: 11, stopPrice: 10),
        Permission::PlaceStopOrders,
    ],
    'sell' => [fn () => new OrderRequest('AAPL_US_EQ', OrderType::Market, OrderSide::Sell, 1), Permission::Sell],
]);

test('an agent may not exceed the max order quantity', function () {
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, roleAttributes: ['max_order_quantity' => 5]);

    app(PlaceOrder::class)->handle($account, coreMarketBuy(5), $agent);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(5.01), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'exceeds');
});

test('the max order value is checked against the limit price', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/metadata/instruments' => Http::response([
            ['ticker' => 'VUSAl_EQ', 'currencyCode' => 'GBP'],
        ]),
    ]);
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, roleAttributes: ['max_order_value' => 500]);
    $limit = fn (float $quantity) => new OrderRequest(
        'VUSAl_EQ',
        OrderType::Limit,
        OrderSide::Buy,
        $quantity,
        limitPrice: 100,
    );

    app(PlaceOrder::class)->handle($account, $limit(5), $agent);

    expect(fn () => app(PlaceOrder::class)->handle($account, $limit(5.1), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £510');
});

test('the max order value of a market order uses the current position price', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response([
            ['instrument' => ['ticker' => 'VUSAl_EQ'], 'currentPrice' => 200, 'quantity' => 1],
        ]),
        'demo.trading212.com/api/v0/equity/metadata/instruments' => Http::response([
            ['ticker' => 'VUSAl_EQ', 'currencyCode' => 'GBP'],
        ]),
        'demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 1, 'status' => 'NEW']),
    ]);
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, roleAttributes: ['max_order_value' => 500]);

    app(PlaceOrder::class)->handle($account, coreMarketBuy(2.5, 'VUSAl_EQ'), $agent);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(3, 'VUSAl_EQ'), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £600');
});

test('a market order is denied when a max value is set and the price is unknown', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/positions' => Http::response([])]);
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, roleAttributes: ['max_order_value' => 500]);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'price could not be determined');
});

test('a market order is denied when a max value is set and the price lookup fails', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/positions' => Http::response(['message' => 'Server error'], 500)]);
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, roleAttributes: ['max_order_value' => 500]);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'price could not be determined');
    Http::assertSentCount(1);
});

test('an agent may not exceed its daily order limit; failed orders do not count', function () {
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, roleAttributes: ['max_orders_per_day' => 2]);
    TradeOrder::factory()->for($account)->for($agent, 'placedBy')->create(['created_at' => now()->subDay()]);
    TradeOrder::factory()->for($account)->for($agent, 'placedBy')->create(['status' => TradeOrderStatus::Failed]);
    TradeOrder::factory()->for($account)->for($agent, 'placedBy')->create();

    app(PlaceOrder::class)->handle($account, coreMarketBuy(), $agent);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Daily order limit of 2 reached');
});

test('an agent may only trade tickers on its allow-list', function () {
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, roleAttributes: ['allowed_tickers' => ['AAPL_US_EQ']]);

    app(PlaceOrder::class)->handle($account, coreMarketBuy(1, 'aapl_us_eq'), $agent);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(1, 'TSLA_US_EQ'), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'may not trade TSLA_US_EQ');
});

test('an agent needs the TradeLive permission for live accounts', function () {
    coreFakeOrderEndpoints();
    $account = TradingAccount::factory()->live()->create();
    $demoTrader = coreAgentFor($account, array_values(array_filter(
        Permission::cases(),
        fn (Permission $p) => $p !== Permission::TradeLive,
    )));
    $liveTrader = coreAgentFor($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(), $demoTrader))
        ->toThrow(AgentNotAuthorizedException::class);

    app(PlaceOrder::class)->handle($account, coreMarketBuy(), $liveTrader);
    Http::assertSentCount(1);
});

test('an agent may not act on an account it is not assigned to', function () {
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor(TradingAccount::factory()->create());

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'not allowed to act on account');
    Http::assertNothingSent();
});

test('an inactive agent may not place orders', function () {
    $account = TradingAccount::factory()->create();
    $agent = coreAgentFor($account, active: false);

    expect(fn () => app(PlaceOrder::class)->handle($account, coreMarketBuy(), $agent))
        ->toThrow(AgentNotAuthorizedException::class);
    Http::assertNothingSent();
});

test('the order placed event broadcasts on the private account channel', function () {
    $tradeOrder = TradeOrder::factory()->create();
    $event = new OrderPlaced($tradeOrder);

    expect($event->broadcastOn()[0]->name)->toBe("private-trading-accounts.{$tradeOrder->trading_account_id}")
        ->and($event->broadcastAs())->toBe('order.placed')
        ->and($event->broadcastWith())->toMatchArray(['id' => $tradeOrder->id, 'status' => 'submitted']);
});
