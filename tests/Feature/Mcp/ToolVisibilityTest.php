<?php

use App\Enums\Permission;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\CancelOrderTool;
use App\Mcp\Tools\GetAccountSummaryTool;
use App\Mcp\Tools\GetMyPermissionsTool;
use App\Mcp\Tools\GetOrderHistoryTool;
use App\Mcp\Tools\GetPortfolioTool;
use App\Mcp\Tools\ListAccountsTool;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradingAccount;
use Laravel\Mcp\Request;

test('a read-only agent cannot see trading tools', function () {
    $agent = AiAgent::factory()->for(Role::factory()->readOnly())->create();
    app(CurrentAgent::class)->set($agent);

    TradingServer::tools()
        ->assertRegistered([
            ListAccountsTool::class,
            GetMyPermissionsTool::class,
            GetPortfolioTool::class,
            GetOrderHistoryTool::class,
        ])
        ->assertNotRegistered([PlaceOrderTool::class, CancelOrderTool::class]);
});

test('an agent with any order permission sees place-order', function () {
    $agent = AiAgent::factory()->for(Role::factory()->withPermissions([Permission::PlaceLimitOrders]))->create();
    app(CurrentAgent::class)->set($agent);

    TradingServer::tools()
        ->assertRegistered([PlaceOrderTool::class, ListAccountsTool::class])
        ->assertNotRegistered([CancelOrderTool::class, GetPortfolioTool::class, GetAccountSummaryTool::class]);
});

test('calling a tool the role lacks fails as not found', function () {
    $agent = AiAgent::factory()->for(Role::factory()->readOnly())->create();
    app(CurrentAgent::class)->set($agent);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'BUY',
        'quantity' => 1,
    ])
        ->assertNotRegistered();
});

test('a tool re-checks the permission when invoked directly and logs the denial', function () {
    $agent = AiAgent::factory()->for(Role::factory()->withPermissions([]))->create();
    $account = TradingAccount::factory()->create();
    $agent->tradingAccounts()->attach($account);
    $currentAgent = app(CurrentAgent::class);
    $currentAgent->set($agent);

    $response = app(GetAccountSummaryTool::class)->handle(new Request(['account_id' => $account->id]), $currentAgent);

    expect($response->isError())->toBeTrue();
    $this->assertDatabaseHas(AgentActivityLog::class, [
        'ai_agent_id' => $agent->id,
        'tool' => 'get-account-summary',
        'outcome' => AgentActivityLog::OUTCOME_DENIED,
    ]);
});

test('no tools are listed without an authenticated agent', function () {
    TradingServer::tools()->assertNotRegistered([ListAccountsTool::class, GetMyPermissionsTool::class]);
});

test('get-my-permissions describes the role and limits', function () {
    $role = Role::factory()->withPermissions([Permission::ViewPortfolio, Permission::PlaceMarketOrders])->create([
        'name' => 'Cautious bot',
        'max_order_quantity' => 5,
        'max_orders_per_day' => 3,
        'allowed_tickers' => ['AAPL_US_EQ'],
    ]);
    $agent = AiAgent::factory()->for($role)->create();
    app(CurrentAgent::class)->set($agent);

    TradingServer::tool(GetMyPermissionsTool::class)
        ->assertOk()
        ->assertStructuredContentPath('role', 'Cautious bot')
        ->assertStructuredContentPath('permissions.*.key', [
            Permission::ViewPortfolio->value,
            Permission::PlaceMarketOrders->value,
        ])
        ->assertStructuredContentPath('limits.max_order_quantity', 5)
        ->assertStructuredContentPath('limits.max_orders_per_day', 3)
        ->assertStructuredContentPath('limits.allowed_tickers', ['AAPL_US_EQ']);
});
