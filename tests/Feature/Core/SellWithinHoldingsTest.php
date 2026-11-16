<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradingAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = TradingAccount::factory()->create();

    $role = Role::factory()->withPermissions(Permission::cases())->create();
    $this->agent = AiAgent::factory()->for($role)->for($this->account->user)->create();
    $this->agent->tradingAccounts()->attach($this->account);
});

/**
 * @param  list<array<string, mixed>>|null  $positions
 * @param  list<array<string, mixed>>  $pendingOrders
 */
function fakeHoldings(?array $positions = null, array $pendingOrders = []): void
{
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response($positions ?? [
            ['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10.5, 'quantityAvailableForTrading' => 10.5],
        ]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response($pendingOrders),
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
    ]);
}

function limitSell(float $quantity, string $ticker = 'VODl_EQ'): OrderRequest
{
    return new OrderRequest($ticker, OrderType::Limit, OrderSide::Sell, $quantity, limitPrice: 412);
}

function assertNoOrderPosted(): void
{
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
}

test('a sell of exactly the holding is placed', function () {
    fakeHoldings([
        ['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 0.3, 'quantityAvailableForTrading' => 0.1 + 0.2],
    ]);

    app(PlaceOrder::class)->handle($this->account, limitSell(0.3), $this->agent);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && (float) $request['quantity'] === -0.3);
});

test('a sell above the holding is denied', function () {
    fakeHoldings();

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(10.6), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Sell quantity 10.6 of VODl_EQ exceeds the 10.5 share(s) available');
    assertNoOrderPosted();
});

test('a sell of a ticker that is not held is denied', function () {
    fakeHoldings();

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(1, 'AAPL_US_EQ'), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'AAPL_US_EQ is not held, so it cannot be sold.');
    assertNoOrderPosted();
});

test('shares in pies cannot be sold', function () {
    fakeHoldings([
        ['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 4, 'quantityInPies' => 6],
    ]);

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(5), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'exceeds the 4 share(s) available');
    assertNoOrderPosted();
});

test('open sell orders reduce the quantity that can be sold', function () {
    fakeHoldings(
        [['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10]],
        [
            ['id' => 1, 'ticker' => 'VODl_EQ', 'side' => 'SELL', 'quantity' => -4, 'filledQuantity' => -1],
            ['id' => 2, 'ticker' => 'VODl_EQ', 'side' => 'SELL', 'quantity' => -3, 'filledQuantity' => 0],
            ['id' => 3, 'ticker' => 'VODl_EQ', 'side' => 'BUY', 'quantity' => 50, 'filledQuantity' => 0],
            ['id' => 4, 'ticker' => 'AAPL_US_EQ', 'side' => 'SELL', 'quantity' => -9, 'filledQuantity' => 0],
        ],
    );

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(5), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'exceeds the 4 share(s) available');
    assertNoOrderPosted();

    app(PlaceOrder::class)->handle($this->account, limitSell(4), $this->agent);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && (float) $request['quantity'] === -4.0);
});

test('an open sell order with only an instrument ticker reduces the quantity that can be sold', function () {
    fakeHoldings(
        [['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10]],
        [['id' => 1, 'instrument' => ['ticker' => 'VODl_EQ'], 'side' => 'SELL', 'quantity' => -7, 'filledQuantity' => 0]],
    );

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(4), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'exceeds the 3 share(s) available');
    assertNoOrderPosted();
});

test('an open order without a recognised side counts as a sell', function (array $pendingOrder) {
    fakeHoldings(
        [['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10]],
        [['id' => 1, 'ticker' => 'VODl_EQ', 'filledQuantity' => 0, ...$pendingOrder]],
    );

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(5), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'exceeds the 4 share(s) available');
    assertNoOrderPosted();
})->with([
    'missing side, negative quantity' => [['quantity' => -6]],
    'missing side, positive quantity' => [['quantity' => 6]],
    'unrecognised side' => [['side' => 'SHORT', 'quantity' => 6]],
]);

test('an open order side is compared case-insensitively', function () {
    fakeHoldings(
        [['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10]],
        [
            ['id' => 1, 'ticker' => 'VODl_EQ', 'side' => 'sell', 'quantity' => -6, 'filledQuantity' => 0],
            ['id' => 2, 'ticker' => 'VODl_EQ', 'side' => 'buy', 'quantity' => 50, 'filledQuantity' => 0],
        ],
    );

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(5), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'exceeds the 4 share(s) available');
    assertNoOrderPosted();
});

test('a sell is denied when no shares are available for trading', function () {
    fakeHoldings([
        ['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 0],
    ]);

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(1e-10), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'exceeds the 0 share(s) available');
    assertNoOrderPosted();
});

test('tickers are matched case-insensitively against holdings and open orders', function () {
    fakeHoldings(
        [['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10]],
        [['id' => 1, 'ticker' => 'vodl_eq', 'side' => 'SELL', 'quantity' => -6, 'filledQuantity' => 0]],
    );

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(5, 'VODL_EQ'), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'exceeds the 4 share(s) available');
    assertNoOrderPosted();

    app(PlaceOrder::class)->handle($this->account, limitSell(4, 'VODL_EQ'), $this->agent);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && (float) $request['quantity'] === -4.0);
});

test('a sell is denied when positions cannot be fetched', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response(['code' => 'InternalError'], 500),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
    ]);

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(1), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Positions or open orders could not be fetched');
    assertNoOrderPosted();
});

test('a sell is denied when open orders cannot be fetched', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response([
            ['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10],
        ]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response(['code' => 'InternalError'], 500),
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
    ]);

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(1), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Positions or open orders could not be fetched');
    assertNoOrderPosted();
});

test('a sell is denied when Trading 212 returns malformed data', function (array $positions, array $pendingOrders) {
    fakeHoldings($positions, $pendingOrders);

    expect(fn () => app(PlaceOrder::class)->handle($this->account, limitSell(1), $this->agent))
        ->toThrow(AgentNotAuthorizedException::class, 'malformed');
    assertNoOrderPosted();
})->with([
    'position without an available quantity' => [
        [['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10]],
        [],
    ],
    'positions that are not a list' => [
        ['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10],
        [],
    ],
    'open order without a ticker' => [
        [['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10]],
        [['id' => 1, 'side' => 'SELL', 'quantity' => -4]],
    ],
    'open sell order without a quantity' => [
        [['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10]],
        [['id' => 1, 'ticker' => 'VODl_EQ', 'side' => 'SELL']],
    ],
]);

test('a buy does not check holdings', function () {
    fakeHoldings([]);

    app(PlaceOrder::class)->handle(
        $this->account,
        new OrderRequest('AAPL_US_EQ', OrderType::Limit, OrderSide::Buy, 1, limitPrice: 100),
        $this->agent,
    );

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/equity/positions'));
});
