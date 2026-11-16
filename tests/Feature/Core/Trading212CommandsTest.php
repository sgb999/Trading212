<?php

use App\Jobs\SyncTradingAccount;
use App\Models\AiAgent;
use App\Models\TradingAccount;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('trading212:sync queues a sync for every account', function () {
    Queue::fake([SyncTradingAccount::class]);
    $accounts = TradingAccount::factory()->count(2)->create();

    $this->artisan('trading212:sync')
        ->expectsOutputToContain('Queued sync for 2 trading account(s).')
        ->assertSuccessful();

    Queue::assertPushedTimes(SyncTradingAccount::class, 2);
    Queue::assertPushed(
        SyncTradingAccount::class,
        fn (SyncTradingAccount $job) => $job->tradingAccount->is($accounts[1]),
    );
});

test('trading212:sync queues a sync for a single account', function () {
    Queue::fake([SyncTradingAccount::class]);
    [$account] = TradingAccount::factory()->count(2)->create();

    $this->artisan('trading212:sync', ['account' => $account->id])->assertSuccessful();

    Queue::assertPushedTimes(SyncTradingAccount::class, 1);
    Queue::assertPushed(SyncTradingAccount::class, fn (SyncTradingAccount $job) => $job->tradingAccount->is($account));
});

test('trading212:sync fails for an unknown account', function (string $account) {
    Queue::fake([SyncTradingAccount::class]);

    $this->artisan('trading212:sync', ['account' => $account])->assertFailed();

    Queue::assertNothingPushed();
})->with(['missing id' => '999999', 'not an id' => 'abc']);

test('trading212:sync is scheduled every minute without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event) => str_contains($event->command, 'trading212:sync'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('* * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('trading212:test-connection prints the account summary', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response([
        'id' => 12345, 'currency' => 'GBP', 'totalValue' => 1500.25, 'cash' => ['availableToTrade' => 300.5],
    ])]);
    $account = TradingAccount::factory()->create(['name' => 'Paper']);

    $this->artisan('trading212:test-connection', ['account' => $account->id])
        ->expectsOutputToContain('Connected to Paper')
        ->expectsTable(['Field', 'Value'], [
            ['Account ID', 12345],
            ['Currency', 'GBP'],
            ['Total value', 1500.25],
            ['Available to trade', 300.5],
            ['Reserved for orders', '-'],
            ['Cash in pies', '-'],
            ['Investments value', '-'],
            ['Unrealized P/L', '-'],
        ])
        ->assertSuccessful();
});

test('trading212:test-connection reports API errors', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response([], 401)]);
    $account = TradingAccount::factory()->create();

    $this->artisan('trading212:test-connection', ['account' => $account->id])
        ->expectsOutputToContain('rejected the API credentials')
        ->assertFailed();
});

test('trading212:agent-token issues a new token and revokes the old one', function () {
    $agent = AiAgent::factory()->create();
    $oldToken = $agent->issueToken();

    $this->artisan('trading212:agent-token', ['agent' => $agent->id])->assertSuccessful();

    expect(AiAgent::findByToken($oldToken))->toBeNull()
        ->and($agent->refresh()->token_prefix)->toStartWith(AiAgent::TOKEN_PREFIX)
        ->and($agent->token_hash)->not->toBe(hash('sha256', $oldToken));
});

test('trading212:agent-token prints a token that authenticates the agent', function () {
    $agent = AiAgent::factory()->create();
    $this->withoutMockingConsoleOutput();

    $exitCode = Artisan::call('trading212:agent-token', ['agent' => $agent->id]);

    preg_match('/'.AiAgent::TOKEN_PREFIX.'\\w+/', Artisan::output(), $matches);
    expect($exitCode)->toBe(0)
        ->and(AiAgent::findByToken($matches[0])?->is($agent))->toBeTrue();
});

test('trading212:agent-token fails for an unknown agent', function () {
    $this->artisan('trading212:agent-token', ['agent' => 999999])->assertFailed();
});
