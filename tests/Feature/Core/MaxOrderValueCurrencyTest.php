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
});

/**
 * @param  list<array<string, mixed>>|null  $instruments  null makes the instrument list request fail
 */
function currencyFakeTrading212(?array $instruments): void
{
    Http::fake([
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
