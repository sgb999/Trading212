<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response([
            ['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 100, 'quantityAvailableForTrading' => 100],
            ['instrument' => ['ticker' => 'AAPL_US_EQ'], 'quantity' => 10, 'quantityAvailableForTrading' => 10],
        ]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
        'demo.trading212.com/api/v0/equity/metadata/instruments' => Http::response([
            ['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX'],
            ['ticker' => 'AAPL_US_EQ', 'currencyCode' => 'USD'],
        ]),
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
    ]);
});

/**
 * @param  list<Permission>|null  $permissions
 * @param  array<string, mixed>  $limits
 */
function sellCapAgent(TradingAccount $account, ?array $permissions = null, array $limits = []): AiAgent
{
    $role = Role::factory()
        ->withPermissions($permissions ?? Permission::cases())
        ->create(['max_order_value' => 50, ...$limits]);

    $agent = AiAgent::factory()->for($role)->for($account->user)->create();
    $agent->tradingAccounts()->attach($account);

    return $agent;
}

function sellCapOrderWasSent(): bool
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/equity/orders/'))->isNotEmpty();
}

test('a sell above the max order value is placed', function (OrderRequest $sell) {
    $account = TradingAccount::factory()->create();

    app(PlaceOrder::class)->handle($account, $sell, sellCapAgent($account));

    expect(sellCapOrderWasSent())->toBeTrue();
})->with([
    'GBX limit sell' => fn () => new OrderRequest('VODl_EQ', OrderType::Limit, OrderSide::Sell, 100, limitPrice: 412),
    'USD limit sell' => fn () => new OrderRequest('AAPL_US_EQ', OrderType::Limit, OrderSide::Sell, 10, limitPrice: 200),
    'market sell' => fn () => new OrderRequest('VODl_EQ', OrderType::Market, OrderSide::Sell, 100),
    'stop sell' => fn () => new OrderRequest('VODl_EQ', OrderType::Stop, OrderSide::Sell, 100, stopPrice: 400),
]);

test('a sell does not look up the instrument currency', function () {
    $account = TradingAccount::factory()->create();

    app(PlaceOrder::class)->handle(
        $account,
        new OrderRequest('AAPL_US_EQ', OrderType::Market, OrderSide::Sell, 10),
        sellCapAgent($account),
    );

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/metadata/instruments'));
});

test('a buy above the max order value is still denied', function () {
    $account = TradingAccount::factory()->create();
    $buy = new OrderRequest('VODl_EQ', OrderType::Limit, OrderSide::Buy, 13, limitPrice: 412);

    expect(fn () => app(PlaceOrder::class)->handle($account, $buy, sellCapAgent($account)))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £53.56 (GBP) exceeds');
    expect(sellCapOrderWasSent())->toBeFalse();
});

test('a buy of a USD instrument is still denied when a max order value is set', function () {
    $account = TradingAccount::factory()->create();
    $buy = new OrderRequest('AAPL_US_EQ', OrderType::Limit, OrderSide::Buy, 1, limitPrice: 0.01);

    expect(fn () => app(PlaceOrder::class)->handle($account, $buy, sellCapAgent($account)))
        ->toThrow(AgentNotAuthorizedException::class, 'AAPL_US_EQ is quoted in USD');
    expect(sellCapOrderWasSent())->toBeFalse();
});

test('a sell without the sell permission is denied', function () {
    $account = TradingAccount::factory()->create();
    $permissions = array_values(array_filter(
        Permission::cases(),
        fn (Permission $permission): bool => $permission !== Permission::Sell,
    ));
    $sell = new OrderRequest('VODl_EQ', OrderType::Limit, OrderSide::Sell, 1, limitPrice: 412);

    expect(fn () => app(PlaceOrder::class)->handle($account, $sell, sellCapAgent($account, $permissions)))
        ->toThrow(AgentNotAuthorizedException::class, 'may not sell');
    expect(sellCapOrderWasSent())->toBeFalse();
});

test('a sell of a ticker the role does not allow is denied', function () {
    $account = TradingAccount::factory()->create();
    $agent = sellCapAgent($account, limits: ['allowed_tickers' => ['AAPL_US_EQ']]);
    $sell = new OrderRequest('VODl_EQ', OrderType::Limit, OrderSide::Sell, 1, limitPrice: 412);

    expect(fn () => app(PlaceOrder::class)->handle($account, $sell, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'may not trade VODl_EQ');
    expect(sellCapOrderWasSent())->toBeFalse();
});

test('a sell above the max quantity is denied', function () {
    $account = TradingAccount::factory()->create();
    $agent = sellCapAgent($account, limits: ['max_order_quantity' => 5]);
    $sell = new OrderRequest('VODl_EQ', OrderType::Limit, OrderSide::Sell, 6, limitPrice: 412);

    expect(fn () => app(PlaceOrder::class)->handle($account, $sell, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Quantity 6 exceeds');
    expect(sellCapOrderWasSent())->toBeFalse();
});

test('a sell at the daily order limit is denied', function () {
    $account = TradingAccount::factory()->create();
    $agent = sellCapAgent($account, limits: ['max_orders_per_day' => 1]);
    TradeOrder::factory()->for($account)->for($agent, 'placedBy')->create();
    $sell = new OrderRequest('VODl_EQ', OrderType::Limit, OrderSide::Sell, 1, limitPrice: 412);

    expect(fn () => app(PlaceOrder::class)->handle($account, $sell, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Daily order limit of 1 reached');
    expect(sellCapOrderWasSent())->toBeFalse();
});
