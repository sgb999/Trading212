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
    Http::fake(['demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW'])]);
});

function extendedHoursAgent(TradingAccount $account): AiAgent
{
    $agent = AiAgent::factory()->for(Role::factory()->withPermissions(Permission::cases()))->for($account->user)->create();
    $agent->tradingAccounts()->attach($account);

    return $agent;
}

function extendedHoursMarketBuy(bool $extendedHours): OrderRequest
{
    return new OrderRequest('AAPL_US_EQ', OrderType::Market, OrderSide::Buy, 1, extendedHours: $extendedHours);
}

test('an agent extended-hours order is denied without an HTTP call or audit record', function () {
    $account = TradingAccount::factory()->create();

    expect(fn () => app(PlaceOrder::class)->handle($account, extendedHoursMarketBuy(true), extendedHoursAgent($account)))
        ->toThrow(AgentNotAuthorizedException::class, 'AI agents may not place extended-hours orders');

    Http::assertNothingSent();
    expect(TradeOrder::query()->count())->toBe(0);
});

test('an agent extended-hours limit order is denied', function () {
    $account = TradingAccount::factory()->create();
    $limitBuy = new OrderRequest('AAPL_US_EQ', OrderType::Limit, OrderSide::Buy, 1, limitPrice: 100, extendedHours: true);

    expect(fn () => app(PlaceOrder::class)->handle($account, $limitBuy, extendedHoursAgent($account)))
        ->toThrow(AgentNotAuthorizedException::class, 'AI agents may not place extended-hours orders');

    Http::assertNothingSent();
});

test('an agent regular-hours order is placed with extended hours off', function () {
    $account = TradingAccount::factory()->create();

    $tradeOrder = app(PlaceOrder::class)->handle($account, extendedHoursMarketBuy(false), extendedHoursAgent($account));

    expect($tradeOrder->extended_hours)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request['extendedHours'] === false);
});

test('a user may still place an extended-hours order', function () {
    $account = TradingAccount::factory()->create();

    $tradeOrder = app(PlaceOrder::class)->handle($account, extendedHoursMarketBuy(true), $account->user);

    expect($tradeOrder->extended_hours)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => $request['extendedHours'] === true);
});
