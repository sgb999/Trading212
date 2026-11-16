<?php

use App\Enums\Permission;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\GetAccountSummaryTool;
use App\Mcp\Tools\GetOrderHistoryTool;
use App\Mcp\Tools\GetPortfolioTool;
use App\Mcp\Tools\ListAccountsTool;
use App\Mcp\Tools\SearchInstrumentsTool;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradingAccount;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = TradingAccount::factory()->create(['name' => 'Paper']);
    $this->agent = AiAgent::factory()->for(Role::factory()->readOnly())->create();
    $this->agent->tradingAccounts()->attach($this->account);
    app(CurrentAgent::class)->set($this->agent);
});

test('list-accounts only returns accessible accounts and never exposes credentials', function () {
    $live = TradingAccount::factory()->live()->create();
    $this->agent->tradingAccounts()->attach($live);
    TradingAccount::factory()->create(['name' => 'Someone else']);

    TradingServer::tool(ListAccountsTool::class)
        ->assertOk()
        ->assertStructuredContentPath('accounts.*.id', [$this->account->id])
        ->assertStructuredContentPath('accounts.0.environment', 'demo')
        ->assertDontSee([$this->account->api_key, $this->account->api_secret]);
});

test('get-account-summary returns the summary and logs an allowed call', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response([
            'id' => 1,
            'currency' => 'GBP',
            'totalValue' => 1234.5,
        ]),
    ]);

    TradingServer::tool(GetAccountSummaryTool::class)
        ->assertOk()
        ->assertStructuredContentPath('account.id', $this->account->id)
        ->assertStructuredContentPath('summary.totalValue', 1234.5);

    $this->assertDatabaseHas(AgentActivityLog::class, [
        'ai_agent_id' => $this->agent->id,
        'trading_account_id' => $this->account->id,
        'tool' => 'get-account-summary',
        'outcome' => AgentActivityLog::OUTCOME_ALLOWED,
    ]);
});

test('get-portfolio passes the ticker filter to Trading 212', function () {
    Http::fake([
        'demo.trading212.com/*' => Http::response([['instrument' => ['ticker' => 'AAPL_US_EQ'], 'quantity' => 3]]),
    ]);

    TradingServer::tool(GetPortfolioTool::class, ['ticker' => 'AAPL_US_EQ'])
        ->assertOk()
        ->assertStructuredContentPath('positions.0.quantity', 3);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url()
        === 'https://demo.trading212.com/api/v0/equity/positions?ticker=AAPL_US_EQ');
});

test('search-instruments filters the instrument list', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/metadata/instruments' => Http::response([
        ['ticker' => 'AAPL_US_EQ', 'name' => 'Apple', 'shortName' => 'AAPL'],
        ['ticker' => 'MSFT_US_EQ', 'name' => 'Microsoft', 'shortName' => 'MSFT'],
    ])]);

    TradingServer::tool(SearchInstrumentsTool::class, ['query' => 'apple'])
        ->assertOk()
        ->assertStructuredContentPath('instruments.*.ticker', ['AAPL_US_EQ']);
});

test('history tools expose the next page cursor', function () {
    Http::fake(['demo.trading212.com/*' => Http::response([
        'items' => [['id' => 9]],
        'nextPagePath' => '/api/v0/equity/history/orders?limit=5&cursor=1700000000',
    ])]);

    TradingServer::tool(GetOrderHistoryTool::class, ['limit' => 5, 'cursor' => '1800000000'])
        ->assertOk()
        ->assertStructuredContentPath('items.0.id', 9)
        ->assertStructuredContentPath('next_cursor', '1700000000');

    Http::assertSent(fn (HttpRequest $request): bool => $request['cursor'] === '1800000000'
        && (int) $request['limit'] === 5);
});

test('a Trading 212 rate limit is surfaced with the retry delay', function () {
    Http::fake(['demo.trading212.com/*' => Http::response(['message' => 'Too many requests'], 429)]);

    TradingServer::tool(GetAccountSummaryTool::class)
        ->assertHasErrors(['rate limit', 'Retry after'])
        ->assertStructuredContentPath('error', 'rate_limited')
        ->assertStructuredContentPath(
            'retry_after_seconds',
            fn (mixed $seconds): bool => is_int($seconds) && $seconds >= 1,
        );

    $this->assertDatabaseHas(AgentActivityLog::class, [
        'tool' => 'get-account-summary',
        'outcome' => AgentActivityLog::OUTCOME_ERROR,
    ]);
});

test('Trading 212 API errors are reported as tool errors', function () {
    Http::fake(['demo.trading212.com/*' => Http::response([], 401)]);

    TradingServer::tool(GetAccountSummaryTool::class)
        ->assertHasErrors(['rejected the API credentials']);
});

test('invalid input is rejected and logged', function () {
    TradingServer::tool(SearchInstrumentsTool::class, ['query' => 'apple', 'limit' => 500])
        ->assertHasErrors();

    $this->assertDatabaseHas(AgentActivityLog::class, [
        'tool' => 'search-instruments',
        'outcome' => AgentActivityLog::OUTCOME_ERROR,
    ]);
    Http::assertNothingSent();
});

test('an agent cannot use an account that is not assigned to it', function () {
    $other = TradingAccount::factory()->create();

    TradingServer::tool(GetAccountSummaryTool::class, ['account_id' => $other->id])
        ->assertHasErrors(['not accessible']);

    Http::assertNothingSent();
    $this->assertDatabaseHas(AgentActivityLog::class, [
        'tool' => 'get-account-summary',
        'trading_account_id' => $other->id,
        'outcome' => AgentActivityLog::OUTCOME_DENIED,
    ]);
});

test('a live account cannot be read without the TradeLive permission', function () {
    $live = TradingAccount::factory()->live()->create();
    $this->agent->tradingAccounts()->attach($live);

    TradingServer::tool(GetAccountSummaryTool::class, ['account_id' => $live->id])
        ->assertHasErrors(['not accessible']);

    Http::assertNothingSent();
});

test('a live account can be read with the TradeLive permission', function () {
    $live = TradingAccount::factory()->live()->create();
    $agent = AiAgent::factory()
        ->for(Role::factory()->withPermissions([Permission::ViewAccount, Permission::TradeLive]))
        ->create();
    $agent->tradingAccounts()->attach($live);
    app(CurrentAgent::class)->set($agent);
    Http::fake(['live.trading212.com/*' => Http::response(['totalValue' => 10])]);

    TradingServer::tool(GetAccountSummaryTool::class)
        ->assertOk()
        ->assertStructuredContentPath('account.environment', 'live');
});

test('an agent without any assigned account gets a clear error', function () {
    $agent = AiAgent::factory()->for(Role::factory()->readOnly())->create();
    app(CurrentAgent::class)->set($agent);

    TradingServer::tool(GetAccountSummaryTool::class)
        ->assertHasErrors(['No trading account is assigned']);
});
