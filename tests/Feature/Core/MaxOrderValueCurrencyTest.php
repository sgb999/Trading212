<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Exceptions\AgentNotAuthorizedException;
use App\Filament\Pages\Trade;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\GetMyPermissionsTool;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradingAccount;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
});

/**
 * @param  list<mixed>|null  $instruments  null makes the instrument list request fail
 * @param  list<mixed>  $positions
 */
function currencyFakeTrading212(?array $instruments, array $positions = []): void
{
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response($positions),
        'demo.trading212.com/api/v0/equity/metadata/instruments' => $instruments === null
            ? Http::response(['message' => 'Server error'], 500)
            : Http::response($instruments),
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
    ]);
}

function currencyAgentWithMaxValue(TradingAccount $account, ?float $maxOrderValue = 50): AiAgent
{
    $role = Role::factory()
        ->withPermissions(Permission::cases())
        ->create(['max_order_value' => $maxOrderValue]);

    $agent = AiAgent::factory()->for($role)->for($account->user)->create();
    $agent->tradingAccounts()->attach($account);

    return $agent;
}

function currencyLimitBuy(string $ticker, float $quantity, float $limitPrice): OrderRequest
{
    return new OrderRequest($ticker, OrderType::Limit, OrderSide::Buy, $quantity, limitPrice: $limitPrice);
}

function currencyOrderWasSent(): bool
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/equity/orders/'))->isNotEmpty();
}

test('a GBX order is converted from pence to pounds and passes a cap it is within', function () {
    currencyFakeTrading212([['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    app(PlaceOrder::class)->handle($account, currencyLimitBuy('VODl_EQ', 10, 412), $agent);

    expect(currencyOrderWasSent())->toBeTrue();
});

test('a GBX order over the cap is denied with its value in pounds', function () {
    currencyFakeTrading212([['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, currencyLimitBuy('VODl_EQ', 13, 412), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £53.56 (GBP) exceeds');
    expect(currencyOrderWasSent())->toBeFalse();
});

test('a GBX market order converts the position current price from pence', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response([
            ['instrument' => ['ticker' => 'BARCl_EQ'], 'currentPrice' => 412, 'quantity' => 1],
        ]),
        'demo.trading212.com/api/v0/equity/metadata/instruments' => Http::response([
            ['ticker' => 'BARCl_EQ', 'currencyCode' => 'GBX'],
        ]),
    ]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);
    $marketBuy = new OrderRequest('BARCl_EQ', OrderType::Market, OrderSide::Buy, 13);

    expect(fn () => app(PlaceOrder::class)->handle($account, $marketBuy, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £53.56 (GBP)');
});

test('a GBP order is valued unchanged', function () {
    currencyFakeTrading212([['ticker' => 'VUSAl_EQ', 'currencyCode' => 'GBP']]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    app(PlaceOrder::class)->handle($account, currencyLimitBuy('VUSAl_EQ', 1, 50), $agent);

    expect(fn () => app(PlaceOrder::class)->handle($account, currencyLimitBuy('VUSAl_EQ', 1, 50.01), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £50.01 (GBP)');
});

test('an instrument in another currency is denied with a message naming the currency', function (string $currencyCode) {
    currencyFakeTrading212([['ticker' => 'FOREIGN_EQ', 'currencyCode' => $currencyCode]]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, currencyLimitBuy('FOREIGN_EQ', 1, 0.01), $agent))
        ->toThrow(AgentNotAuthorizedException::class, "FOREIGN_EQ is quoted in {$currencyCode}");
    expect(currencyOrderWasSent())->toBeFalse();
})->with(['USD', 'EUR']);

test('an instrument missing from the list is denied', function () {
    currencyFakeTrading212([['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, currencyLimitBuy('UNKNOWN_EQ', 1, 1), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'instrument UNKNOWN_EQ was not found');
    expect(currencyOrderWasSent())->toBeFalse();
});

test('an instrument without a currency code is denied', function () {
    currencyFakeTrading212([['ticker' => 'VODl_EQ']]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, currencyLimitBuy('VODl_EQ', 1, 1), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'the currency of VODl_EQ is unknown');
    expect(currencyOrderWasSent())->toBeFalse();
});

test('an order is denied when the instrument list cannot be fetched', function () {
    currencyFakeTrading212(null);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, currencyLimitBuy('VODl_EQ', 1, 1), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'instrument list could not be fetched');
    expect(currencyOrderWasSent())->toBeFalse();
});

test('the instrument currency is not looked up when the role has no max order value', function () {
    currencyFakeTrading212(null);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account, maxOrderValue: null);

    app(PlaceOrder::class)->handle($account, currencyLimitBuy('AAPL_US_EQ', 1, 1000), $agent);

    expect(currencyOrderWasSent())->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/metadata/instruments'));
});

test('a max order value is compared unrounded', function () {
    currencyFakeTrading212([['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, currencyLimitBuy('VODl_EQ', 1, 5000.4), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £50.00 (GBP)');
    expect(currencyOrderWasSent())->toBeFalse();
});

test('a STOP_LIMIT order is valued at the higher of its limit and stop prices', function () {
    currencyFakeTrading212([['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);
    $stopLimit = fn (float $limitPrice, float $stopPrice): OrderRequest => new OrderRequest(
        'VODl_EQ',
        OrderType::StopLimit,
        OrderSide::Buy,
        11,
        limitPrice: $limitPrice,
        stopPrice: $stopPrice,
    );

    expect(fn () => app(PlaceOrder::class)->handle($account, $stopLimit(300, 500), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £55.00 (GBP)');
    expect(fn () => app(PlaceOrder::class)->handle($account, $stopLimit(500, 300), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £55.00 (GBP)');
    expect(currencyOrderWasSent())->toBeFalse();
});

test('a STOP order is valued at the higher of its stop and the current price', function (float $stopPrice, float $currentPrice) {
    currencyFakeTrading212(
        [['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']],
        [['instrument' => ['ticker' => 'VODl_EQ'], 'currentPrice' => $currentPrice, 'quantity' => 1]],
    );
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);
    $stop = new OrderRequest('VODl_EQ', OrderType::Stop, OrderSide::Buy, 11, stopPrice: $stopPrice);

    expect(fn () => app(PlaceOrder::class)->handle($account, $stop, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'Estimated order value £55.00 (GBP)');
    expect(currencyOrderWasSent())->toBeFalse();
})->with([
    'stop below the market' => [100, 500],
    'stop above the market' => [500, 100],
]);

test('a STOP order within the cap is placed', function () {
    currencyFakeTrading212(
        [['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']],
        [['instrument' => ['ticker' => 'VODl_EQ'], 'currentPrice' => 400, 'quantity' => 1]],
    );
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    app(PlaceOrder::class)->handle($account, new OrderRequest('VODl_EQ', OrderType::Stop, OrderSide::Buy, 10, stopPrice: 500), $agent);

    expect(currencyOrderWasSent())->toBeTrue();
});

test('a STOP order on a ticker that is not held is denied and suggests STOP_LIMIT', function () {
    currencyFakeTrading212([['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);
    $stop = new OrderRequest('VODl_EQ', OrderType::Stop, OrderSide::Buy, 1, stopPrice: 1);

    expect(fn () => app(PlaceOrder::class)->handle($account, $stop, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'use a STOP_LIMIT order');
    expect(currencyOrderWasSent())->toBeFalse();
});

test('malformed instrument and position lists are denied rather than erroring', function () {
    currencyFakeTrading212(['garbage', 123, null], ['garbage', ['instrument' => 'VODl_EQ'], 42]);
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);
    $marketBuy = new OrderRequest('VODl_EQ', OrderType::Market, OrderSide::Buy, 1);

    expect(fn () => app(PlaceOrder::class)->handle($account, $marketBuy, $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'price could not be determined');
    expect(fn () => app(PlaceOrder::class)->handle($account, currencyLimitBuy('VODl_EQ', 1, 1), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'instrument VODl_EQ was not found');
});

test('a non-numeric current price is treated as unknown', function () {
    currencyFakeTrading212(
        [['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']],
        [['instrument' => ['ticker' => 'VODl_EQ'], 'currentPrice' => 'n/a', 'quantity' => 1]],
    );
    $account = TradingAccount::factory()->create();
    $agent = currencyAgentWithMaxValue($account);

    expect(fn () => app(PlaceOrder::class)->handle($account, new OrderRequest('VODl_EQ', OrderType::Market, OrderSide::Buy, 1000), $agent))
        ->toThrow(AgentNotAuthorizedException::class, 'price could not be determined');
});

test('the place-order tool rejects a price the order type does not use', function (array $prices, string $field) {
    currencyFakeTrading212([['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']]);
    $account = TradingAccount::factory()->create();
    app(CurrentAgent::class)->set(currencyAgentWithMaxValue($account));

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'VODl_EQ',
        'side' => 'BUY',
        'quantity' => 1000,
        ...$prices,
    ])->assertHasErrors([$field]);

    expect(currencyOrderWasSent())->toBeFalse();
})->with([
    'limit price on MARKET' => [['type' => 'MARKET', 'limit_price' => 0.01], 'limit price field is prohibited'],
    'limit price on STOP' => [['type' => 'STOP', 'stop_price' => 1, 'limit_price' => 0.01], 'limit price field is prohibited'],
    'stop price on MARKET' => [['type' => 'MARKET', 'stop_price' => 0.01], 'stop price field is prohibited'],
    'stop price on LIMIT' => [['type' => 'LIMIT', 'limit_price' => 1, 'stop_price' => 0.01], 'stop price field is prohibited'],
]);

test('get-my-permissions states that the max order value is in GBP', function () {
    $account = TradingAccount::factory()->create();
    app(CurrentAgent::class)->set(currencyAgentWithMaxValue($account));

    TradingServer::tool(GetMyPermissionsTool::class)
        ->assertOk()
        ->assertStructuredContentPath('limits.max_order_value', 50)
        ->assertStructuredContentPath('limits.max_order_value_currency', 'GBP');
});

test('the trade page does not send a hidden limit price after switching to a market order', function () {
    currencyFakeTrading212([['ticker' => 'VODl_EQ', 'currencyCode' => 'GBX']]);
    Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 1, 'status' => 'NEW'])]);
    $user = TradingAccount::factory()->default()->create()->user;
    $this->actingAs($user);

    Livewire::test(Trade::class)
        ->fillForm(['ticker' => 'VODl_EQ', 'side' => 'BUY', 'type' => 'LIMIT', 'quantity' => 1, 'limit_price' => 400])
        ->fillForm(['type' => 'MARKET'])
        ->callAction('placeOrder')
        ->assertHasNoFormErrors()
        ->assertNotified('Order placed');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/equity/orders/market'));
});
