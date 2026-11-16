<?php

use App\Enums\Permission;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\GetMyPermissionsTool;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AiAgent;
use App\Models\PortfolioSnapshot;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

const INVESTOR_EXTERNAL_ID = 7_070_707;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-11-16 11:00', 'Europe/London')->utc());
});

function seededInvestor(): Role
{
    test()->seed(RoleSeeder::class);

    return Role::query()->where('slug', 'investor')->sole();
}

/**
 * @param  list<string>|null  $allowedTickers
 */
function mcpInvestor(TradingAccount $account, ?array $allowedTickers = ['VUSA_EQ']): AiAgent
{
    $role = seededInvestor();
    $role->update(['allowed_tickers' => $allowedTickers]);

    $agent = AiAgent::factory()->for($role)->for($account->user)->create();
    $agent->tradingAccounts()->attach($account);
    app(CurrentAgent::class)->set($agent);

    return $agent;
}

/**
 * A snapshot this morning and a fresh summary at the same value, so the loss guards pass.
 */
function investorHealthyPortfolio(TradingAccount $account): void
{
    PortfolioSnapshot::factory()->for($account)->create([
        'external_account_id' => INVESTOR_EXTERNAL_ID,
        'total_value' => 200,
        'cash' => 200,
        'invested' => 0,
        'currency' => 'GBP',
        'taken_at' => Carbon::parse('2026-11-16 08:05', 'Europe/London')->utc(),
    ]);

    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response([
            'id' => INVESTOR_EXTERNAL_ID,
            'currency' => 'GBP',
            'totalValue' => 200,
            'cash' => ['availableToTrade' => 200, 'reservedForOrders' => 0, 'inPies' => 0],
            'investments' => ['currentValue' => 0],
        ]),
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 901, 'status' => 'NEW']),
    ]);
}

function investorAssertNoOrderSent(): void
{
    Http::assertNotSent(fn (HttpRequest $request): bool => $request->method() === 'POST');
}

test('the seeder creates the investor preset with exactly its permissions and limits', function () {
    $investor = seededInvestor();

    expect($investor->name)->toBe('Investor')
        ->and($investor->permissions)->toEqualCanonicalizing([
            ...array_map(fn (Permission $permission) => $permission->value, Permission::readOnly()),
            Permission::PlaceLimitOrders->value,
            Permission::PlaceStopOrders->value,
            Permission::Sell->value,
            Permission::CancelOrders->value,
        ])
        ->and($investor->hasPermission(Permission::PlaceMarketOrders))->toBeFalse()
        ->and($investor->hasPermission(Permission::TradeLive))->toBeFalse()
        ->and($investor->max_order_quantity)->toBeNull()
        ->and($investor->max_order_value)->toBe('50.00')
        ->and($investor->max_orders_per_day)->toBe(2)
        ->and($investor->max_daily_loss_percent)->toBe('5.00')
        ->and($investor->min_portfolio_value)->toBe('150.00')
        ->and($investor->allowed_tickers)->toBeNull()
        ->and($investor->restrict_to_allowed_tickers)->toBeTrue();
});

test('the empty ticker placeholder denies every ticker until it is filled in', function () {
    $investor = seededInvestor();

    expect($investor->allowsTicker('VUSA_EQ'))->toBeFalse();

    $investor->update(['allowed_tickers' => ['VUSA_EQ']]);

    expect($investor->allowsTicker('vusa_eq'))->toBeTrue()
        ->and($investor->allowsTicker('AAPL_US_EQ'))->toBeFalse();
});

test('an empty ticker list still allows any ticker on roles that do not restrict to it', function () {
    $role = Role::factory()->create(['allowed_tickers' => null]);

    expect($role->restrict_to_allowed_tickers)->toBeFalse()
        ->and($role->allowsTicker('AAPL_US_EQ'))->toBeTrue();
});

test('re-seeding leaves an existing investor preset exactly as the owner edited it', function () {
    $investor = seededInvestor();
    $ownerPermissions = [
        ...array_map(fn (Permission $permission) => $permission->value, Permission::readOnly()),
        Permission::PlaceLimitOrders->value,
        Permission::Sell->value,
        Permission::CancelOrders->value,
        Permission::TradeLive->value,
    ];
    $investor->update([
        'permissions' => $ownerPermissions,
        'max_order_value' => 40,
        'allowed_tickers' => ['VUSA_EQ', 'VWRL_EQ'],
    ]);

    $this->seed(RoleSeeder::class);
    $this->seed(RoleSeeder::class);

    $reseeded = Role::query()->where('slug', 'investor')->sole();

    expect(Role::query()->where('slug', 'investor')->count())->toBe(1)
        ->and($reseeded->id)->toBe($investor->id)
        ->and($reseeded->permissions)->toEqualCanonicalizing($ownerPermissions)
        ->and($reseeded->hasPermission(Permission::TradeLive))->toBeTrue()
        ->and($reseeded->hasPermission(Permission::PlaceStopOrders))->toBeFalse()
        ->and($reseeded->max_order_value)->toBe('40.00')
        ->and($reseeded->allowed_tickers)->toBe(['VUSA_EQ', 'VWRL_EQ'])
        ->and($reseeded->restrict_to_allowed_tickers)->toBeTrue();
});

test('re-seeding recreates the investor preset with its defaults when it was deleted', function () {
    seededInvestor()->delete();

    $this->seed(RoleSeeder::class);

    $recreated = Role::query()->where('slug', 'investor')->sole();

    expect($recreated->max_order_value)->toBe('50.00')
        ->and($recreated->hasPermission(Permission::TradeLive))->toBeFalse()
        ->and($recreated->allowed_tickers)->toBeNull()
        ->and($recreated->restrict_to_allowed_tickers)->toBeTrue();
});

test('re-seeding keeps the placeholder empty when the tickers were never filled in', function () {
    seededInvestor();

    $this->seed(RoleSeeder::class);

    expect(Role::query()->where('slug', 'investor')->sole()->allowed_tickers)->toBeNull();
});

test('an investor agent is denied any order while its ticker list is empty', function () {
    $account = TradingAccount::factory()->create(['external_account_id' => INVESTOR_EXTERNAL_ID]);
    investorHealthyPortfolio($account);
    mcpInvestor($account, allowedTickers: null);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'VUSA_EQ',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'quantity' => 1,
        'limit_price' => 10,
    ])
        ->assertHasErrors(['no allowed tickers yet']);

    investorAssertNoOrderSent();
});

test('an investor agent is denied a market order', function (string $side) {
    $account = TradingAccount::factory()->create(['external_account_id' => INVESTOR_EXTERNAL_ID]);
    investorHealthyPortfolio($account);
    mcpInvestor($account);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'VUSA_EQ',
        'type' => 'MARKET',
        'side' => $side,
        'quantity' => 1,
    ])
        ->assertHasErrors(['may not place Market orders']);

    investorAssertNoOrderSent();
})->with(['BUY', 'SELL']);

test('an investor agent is denied a live account', function () {
    $live = TradingAccount::factory()->live()->create(['external_account_id' => INVESTOR_EXTERNAL_ID]);
    investorHealthyPortfolio($live);
    mcpInvestor($live);

    TradingServer::tool(PlaceOrderTool::class, [
        'account_id' => $live->id,
        'ticker' => 'VUSA_EQ',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'quantity' => 1,
        'limit_price' => 10,
    ])
        ->assertHasErrors(['not accessible']);

    Http::assertNothingSent();
});

test('an investor agent is denied a limit buy worth more than £50', function () {
    $account = TradingAccount::factory()->create(['external_account_id' => INVESTOR_EXTERNAL_ID]);
    investorHealthyPortfolio($account);
    mcpInvestor($account);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'VUSA_EQ',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'quantity' => 1,
        'limit_price' => 50.01,
    ])
        ->assertHasErrors(['exceeds the role']);

    investorAssertNoOrderSent();
});

test('an investor agent may place a limit buy of £50 on an allowed ticker', function () {
    $account = TradingAccount::factory()->create(['external_account_id' => INVESTOR_EXTERNAL_ID]);
    investorHealthyPortfolio($account);
    $agent = mcpInvestor($account);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'VUSA_EQ',
        'type' => 'LIMIT',
        'side' => 'BUY',
        'quantity' => 1,
        'limit_price' => 50,
    ])
        ->assertOk();

    Http::assertSent(fn (HttpRequest $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://demo.trading212.com/api/v0/equity/orders/limit');
    $this->assertDatabaseHas(TradeOrder::class, ['placed_by_id' => $agent->id, 'external_id' => 901]);
});

test('the permissions and place order tools tell agents an empty restricted ticker list allows no ticker', function (string $tool) {
    expect(app($tool)->description())
        ->toContain('restrict_to_allowed_tickers')
        ->toContain('empty or null')
        ->toMatch('/no ticker is allowed/i');
})->with([GetMyPermissionsTool::class, PlaceOrderTool::class]);
