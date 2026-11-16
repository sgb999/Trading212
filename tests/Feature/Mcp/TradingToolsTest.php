<?php

use App\Enums\Permission;
use App\Enums\TradeOrderStatus;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\CancelOrderTool;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = TradingAccount::factory()->create(['api_key' => 'demo-key', 'api_secret' => 'demo-secret']);
});

function mcpTrader(TradingAccount $account, ?Role $role = null): AiAgent
{
    $agent = AiAgent::factory()->for($role ?? Role::factory()->trader()->create())->create();
    $agent->tradingAccounts()->attach($account);
    app(CurrentAgent::class)->set($agent);

    return $agent;
}

test('a market sell is sent with a negative quantity and Basic auth', function () {
    $agent = mcpTrader($this->account);
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response([
            ['instrument' => ['ticker' => 'AAPL_US_EQ'], 'quantity' => 2, 'quantityAvailableForTrading' => 2],
        ]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 555, 'status' => 'NEW']),
    ]);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'SELL',
        'quantity' => 2,
    ])
        ->assertOk()
        ->assertStructuredContentPath('order.id', 555)
        ->assertStructuredContentPath('order.side', 'SELL');

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://demo.trading212.com/api/v0/equity/orders/market'
        && $request['ticker'] === 'AAPL_US_EQ'
        && (float) $request['quantity'] === -2.0
        && $request['extendedHours'] === false
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('demo-key:demo-secret')));
    $this->assertDatabaseHas(TradeOrder::class, ['placed_by_id' => $agent->id, 'external_id' => 555]);
    $this->assertDatabaseHas(AgentActivityLog::class, [
        'tool' => 'place-order',
        'outcome' => AgentActivityLog::OUTCOME_ALLOWED,
        'trading_account_id' => $this->account->id,
    ]);
});

test('a sell above the max order value but within holdings is accepted', function () {
    mcpTrader($this->account, Role::factory()->trader()->create(['max_order_value' => 50]));
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response([
            ['instrument' => ['ticker' => 'VODl_EQ'], 'quantity' => 100, 'quantityAvailableForTrading' => 100],
        ]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders/limit' => Http::response(['id' => 557, 'status' => 'NEW']),
    ]);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'VODl_EQ',
        'type' => 'LIMIT',
        'side' => 'SELL',
        'quantity' => 100,
        'limit_price' => 412,
    ])
        ->assertOk()
        ->assertStructuredContentPath('order.id', 557);

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://demo.trading212.com/api/v0/equity/orders/limit'
        && (float) $request['quantity'] === -100.0);
});

test('a limit buy is sent with its limit price and time validity', function () {
    mcpTrader($this->account);
    Http::fake(['demo.trading212.com/api/v0/equity/orders/limit' => Http::response(['id' => 556, 'status' => 'NEW'])]);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'quantity' => 1.5,
        'limit_price' => 150.25,
        'time_validity' => 'GOOD_TILL_CANCEL',
    ])->assertOk();

    Http::assertSent(fn (HttpRequest $request): bool => $request->url()
        === 'https://demo.trading212.com/api/v0/equity/orders/limit'
        && (float) $request['quantity'] === 1.5
        && (float) $request['limitPrice'] === 150.25
        && $request['timeValidity'] === 'GOOD_TILL_CANCEL');
});

test('selling is denied and logged without the Sell permission', function () {
    $agent = mcpTrader($this->account, Role::factory()->withPermissions([Permission::PlaceMarketOrders])->create());

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'SELL',
        'quantity' => 1,
    ])
        ->assertHasErrors(['Permission denied', 'may not sell']);

    Http::assertNothingSent();
    $log = AgentActivityLog::query()->sole();
    expect($log->ai_agent_id)->toBe($agent->id)
        ->and($log->outcome)->toBe(AgentActivityLog::OUTCOME_DENIED)
        ->and($log->trading_account_id)->toBe($this->account->id)
        ->and($log->arguments)->toMatchArray(['side' => 'SELL', 'quantity' => 1]);
});

test('an order type outside the role is denied', function () {
    mcpTrader($this->account, Role::factory()->withPermissions([Permission::PlaceMarketOrders])->create());

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'quantity' => 1,
        'limit_price' => 10,
    ])
        ->assertHasErrors(['may not place Limit orders']);

    Http::assertNothingSent();
});

test('trading on a live account is blocked without TradeLive', function () {
    $live = TradingAccount::factory()->live()->create();
    $agent = mcpTrader($this->account);
    $agent->tradingAccounts()->attach($live);

    TradingServer::tool(PlaceOrderTool::class, [
        'account_id' => $live->id,
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'BUY',
        'quantity' => 1,
    ])
        ->assertHasErrors(['not accessible']);

    Http::assertNothingSent();
    $this->assertDatabaseHas(AgentActivityLog::class, [
        'tool' => 'place-order',
        'outcome' => AgentActivityLog::OUTCOME_DENIED,
        'trading_account_id' => $live->id,
    ]);
});

test('role limits enforced by PlaceOrder are reported as denials', function () {
    mcpTrader($this->account, Role::factory()->trader()->create(['max_order_quantity' => 5]));

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'BUY',
        'quantity' => 10,
    ])
        ->assertHasErrors(['exceeds the role']);

    Http::assertNothingSent();
});

test('order input is validated before anything is sent', function (array $arguments, string $error) {
    mcpTrader($this->account);

    TradingServer::tool(PlaceOrderTool::class, [
        ...['ticker' => 'AAPL_US_EQ', 'type' => 'MARKET', 'side' => 'BUY', 'quantity' => 1],
        ...$arguments,
    ])
        ->assertHasErrors([$error]);

    Http::assertNothingSent();
    $this->assertDatabaseHas(AgentActivityLog::class, [
        'tool' => 'place-order',
        'outcome' => AgentActivityLog::OUTCOME_ERROR,
    ]);
})->with([
    'unknown type' => [['type' => 'TRAILING'], 'type'],
    'negative quantity' => [['quantity' => -1], 'quantity'],
    'limit order without price' => [['type' => 'LIMIT'], 'limit price is required'],
]);

test('a rejected order surfaces the Trading 212 error', function () {
    mcpTrader($this->account);
    Http::fake(['demo.trading212.com/*' => Http::response(['code' => 'InsufficientFreeForStocksBuy'], 400)]);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'BUY',
        'quantity' => 1,
    ])
        ->assertHasErrors(['InsufficientFreeForStocksBuy']);

    $this->assertDatabaseHas(TradeOrder::class, ['status' => TradeOrderStatus::Failed]);
});

test('cancel-order cancels the pending order', function () {
    mcpTrader($this->account);
    Http::fake(['demo.trading212.com/api/v0/equity/orders/777' => Http::response()]);

    TradingServer::tool(CancelOrderTool::class, ['order_id' => 777])
        ->assertOk()
        ->assertStructuredContentPath('cancelled_order_id', 777);

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://demo.trading212.com/api/v0/equity/orders/777');
    $this->assertDatabaseHas(AgentActivityLog::class, [
        'tool' => 'cancel-order',
        'outcome' => AgentActivityLog::OUTCOME_ALLOWED,
    ]);
});

test('cancel-order cannot touch an unassigned account', function () {
    mcpTrader($this->account);
    $other = TradingAccount::factory()->create();

    TradingServer::tool(CancelOrderTool::class, ['account_id' => $other->id, 'order_id' => 777])
        ->assertHasErrors(['not accessible']);

    Http::assertNothingSent();
});
