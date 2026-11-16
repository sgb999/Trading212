<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TradeOrderStatus;
use App\Jobs\SyncTradingAccount;
use App\Listeners\SendOrderPlacedDiscordNotification;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradingAccount;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

const DISCORD_TEST_WEBHOOK = 'https://discord.com/api/webhooks/123/secret-token';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->freezeSecond();
});

function discordAgentFor(TradingAccount $account, string $name = 'Investor Bot'): AiAgent
{
    $role = Role::factory()
        ->withPermissions(Permission::cases())
        ->create(['max_order_value' => null, 'max_order_quantity' => null]);

    $agent = AiAgent::factory()->for($role)->for($account->user)->create(['name' => $name]);
    $agent->tradingAccounts()->attach($account);

    return $agent;
}

/**
 * @return array<string, string>
 */
function discordFieldsOf(Request $request): array
{
    return collect($request['embeds'][0]['fields'] ?? [])->pluck('value', 'name')->all();
}

function discordFakeOrderEndpoints(mixed $discordResponse = null): void
{
    Http::fake([
        'demo.trading212.com/api/v0/equity/orders/*' => Http::response(['id' => 555, 'status' => 'NEW']),
        'discord.com/*' => $discordResponse ?? Http::response(status: 204),
    ]);
}

/**
 * Fakes one account-summary response per upcoming sync: true succeeds, false returns a 500.
 */
function discordFakeSyncOutcomes(bool ...$outcomes): void
{
    $summaries = Http::sequence();

    foreach ($outcomes as $isHealthy) {
        $isHealthy
            ? $summaries->push(['id' => 1, 'currency' => 'GBP'])
            : $summaries->push(['message' => 'Server error'], 500);
    }

    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => $summaries,
        'demo.trading212.com/api/v0/equity/positions' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
        'discord.com/*' => Http::response(status: 204),
    ]);
}

/**
 * Runs the given number of syncs, moving past the Trading 212 rate-limit windows after each.
 */
function discordRunSyncs(TradingAccount $account, int $times = 1): void
{
    foreach (range(1, $times) as $ignored) {
        SyncTradingAccount::dispatchSync($account);
        test()->travel(10)->seconds();
    }
}

function discordAlertsSent(): int
{
    return Http::recorded(fn (Request $request) => str_starts_with($request->url(), DISCORD_TEST_WEBHOOK))->count();
}

test('the order listener is queued', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    discordFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    Queue::fake([CallQueuedListener::class]);

    app(PlaceOrder::class)->handle(
        $account,
        new OrderRequest(ticker: 'AAPL_US_EQ', type: OrderType::Market, side: OrderSide::Buy, quantity: 1),
        $account->user,
    );

    Queue::assertPushed(
        CallQueuedListener::class,
        fn (CallQueuedListener $job) => $job->class === SendOrderPlacedDiscordNotification::class,
    );
});

test('an agent order posts ticker, side, quantity, price, status and the agent name to discord', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    discordFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    $agent = discordAgentFor($account, 'Investor Bot');

    app(PlaceOrder::class)->handle(
        $account,
        new OrderRequest(ticker: 'VUSAl_EQ', type: OrderType::Limit, side: OrderSide::Buy, quantity: 1.5, limitPrice: 92.25),
        $agent,
    );

    Http::assertSent(fn (Request $request) => $request->url() === DISCORD_TEST_WEBHOOK
        && $request->method() === 'POST'
        && discordFieldsOf($request) === [
            'Ticker' => 'VUSAl_EQ',
            'Side' => 'BUY',
            'Quantity' => '1.5',
            'Price' => 'Limit 92.25',
            'Status' => TradeOrderStatus::Submitted->value,
            'Placed by' => 'Agent: Investor Bot',
        ]);
});

test('a user market order names the user and reports a market price', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    discordFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();
    $account->user->update(['name' => 'Liam']);

    app(PlaceOrder::class)->handle(
        $account,
        new OrderRequest(ticker: 'AAPL_US_EQ', type: OrderType::Market, side: OrderSide::Sell, quantity: 2),
        $account->user,
    );

    Http::assertSent(fn (Request $request) => $request->url() === DISCORD_TEST_WEBHOOK
        && discordFieldsOf($request)['Side'] === 'SELL'
        && discordFieldsOf($request)['Price'] === 'Market'
        && discordFieldsOf($request)['Placed by'] === 'User: Liam');
});

test('nothing is sent or queued for discord when no webhook url is configured', function () {
    config(['services.discord.webhook_url' => '']);
    discordFakeOrderEndpoints();
    $account = TradingAccount::factory()->create();

    app(PlaceOrder::class)->handle(
        $account,
        new OrderRequest(ticker: 'AAPL_US_EQ', type: OrderType::Market, side: OrderSide::Buy, quantity: 1),
        discordAgentFor($account),
    );

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'discord.com'));
});

test('a failing discord webhook does not break order placement', function (mixed $discordResponse) {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    discordFakeOrderEndpoints($discordResponse);
    $account = TradingAccount::factory()->create();

    $tradeOrder = app(PlaceOrder::class)->handle(
        $account,
        new OrderRequest(ticker: 'AAPL_US_EQ', type: OrderType::Market, side: OrderSide::Buy, quantity: 1),
        discordAgentFor($account),
    );

    expect($tradeOrder->refresh())
        ->status->toBe(TradeOrderStatus::Submitted)
        ->external_id->toBe(555);
})->with([
    'server error' => fn () => Http::response(['message' => 'down'], 500),
    'connection failure' => fn () => Http::failedConnection(),
]);

test('three consecutive sync failures post exactly one alert', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    discordFakeSyncOutcomes(false, false, false, false, false);
    $account = TradingAccount::factory()->create(['name' => 'Stocks ISA']);

    discordRunSyncs($account, 2);
    expect(discordAlertsSent())->toBe(0);

    discordRunSyncs($account);
    expect(discordAlertsSent())->toBe(1);
    Http::assertSent(fn (Request $request) => $request->url() === DISCORD_TEST_WEBHOOK
        && str_contains($request['content'], '3 times in a row')
        && str_contains($request['content'], 'Stocks ISA'));

    discordRunSyncs($account, 2);
    expect(discordAlertsSent())->toBe(1);
});

test('a successful sync resets the failure streak', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    discordFakeSyncOutcomes(false, false, true, false, false, false);
    $account = TradingAccount::factory()->create();

    discordRunSyncs($account, 5);
    expect(discordAlertsSent())->toBe(0);

    discordRunSyncs($account);
    expect(discordAlertsSent())->toBe(1);
});

test('a new streak after a success alerts again', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    discordFakeSyncOutcomes(false, false, false, true, false, false, false);
    $account = TradingAccount::factory()->create();

    discordRunSyncs($account, 7);

    expect(discordAlertsSent())->toBe(2);
});

test('failure streaks are tracked per account', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    discordFakeSyncOutcomes(false, false, false, false);
    [$first, $second] = TradingAccount::factory()->count(2)->create();

    discordRunSyncs($first);
    discordRunSyncs($second);
    discordRunSyncs($first);
    discordRunSyncs($second);

    expect(discordAlertsSent())->toBe(0);
});

test('unexpected job failures count towards the streak', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    Http::fake(['discord.com/*' => Http::response(status: 204)]);
    $account = TradingAccount::factory()->create();
    $job = new SyncTradingAccount($account);

    $job->failed(new RuntimeException('Database went away'));
    $job->failed(new RuntimeException('Database went away'));
    $job->failed(new RuntimeException('Database went away'));

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => str_contains($request['content'], 'Database went away'));
});

test('a failing discord webhook does not break the sync', function () {
    config(['services.discord.webhook_url' => DISCORD_TEST_WEBHOOK]);
    Http::fake([
        'demo.trading212.com/api/v0/*' => Http::response(['message' => 'Server error'], 500),
        'discord.com/*' => Http::failedConnection(),
    ]);
    $account = TradingAccount::factory()->create();

    discordRunSyncs($account, 3);

    expect(cache()->get(SyncTradingAccount::failureCountKey($account)))->toBe(3);
});

test('no sync alert is sent when no webhook url is configured', function () {
    config(['services.discord.webhook_url' => null]);
    discordFakeSyncOutcomes(false, false, false);
    $account = TradingAccount::factory()->create();

    discordRunSyncs($account, 3);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'discord.com'));
});
