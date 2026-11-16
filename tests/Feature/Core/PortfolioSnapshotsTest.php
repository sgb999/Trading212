<?php

use App\Actions\RecordPortfolioSnapshot;
use App\Events\PortfolioUpdated;
use App\Jobs\SnapshotPortfolios;
use App\Jobs\SyncTradingAccount;
use App\Models\PortfolioSnapshot;
use App\Models\TradingAccount;
use App\Services\Trading212\Trading212RateLimiter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->freezeSecond();
});

/**
 * A consistent AccountSummary: cash is always 50.50 and investments make up the rest of the total.
 *
 * @return array<string, mixed>
 */
function snapshotSummary(float $totalValue = 200.5, int $id = 98765): array
{
    return [
        'id' => $id,
        'currency' => 'GBP',
        'totalValue' => $totalValue,
        'cash' => ['availableToTrade' => 40.25, 'reservedForOrders' => 10, 'inPies' => 0.25],
        'investments' => ['currentValue' => $totalValue - 50.5, 'totalCost' => 160],
    ];
}

/**
 * @param  array<string, mixed>|null  $summary
 */
function fakeSyncResponses(?array $summary = null): void
{
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response($summary ?? snapshotSummary()),
        'demo.trading212.com/api/v0/equity/positions' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
    ]);
}

test('a sync records a snapshot mapped from the account summary', function () {
    fakeSyncResponses();
    $account = TradingAccount::factory()->create(['currency' => null]);

    SyncTradingAccount::dispatchSync($account);

    expect($account->portfolioSnapshots()->sole())
        ->total_value->toBe('200.5000')
        ->cash->toBe('50.5000')
        ->invested->toBe('150.0000')
        ->currency->toBe('GBP')
        ->external_account_id->toBe(98765)
        ->taken_at->toEqual(now());
});

test('a sync records a snapshot from an all-cash summary in the shape Trading 212 returns', function () {
    fakeSyncResponses([
        'id' => 12345678,
        'currency' => 'GBP',
        'totalValue' => 200.0,
        'cash' => ['availableToTrade' => 200.0, 'reservedForOrders' => 0, 'inPies' => 0],
        'investments' => ['currentValue' => 0, 'totalCost' => 0, 'realizedProfitLoss' => -1.5, 'unrealizedProfitLoss' => 0],
    ]);
    $account = TradingAccount::factory()->create();

    SyncTradingAccount::dispatchSync($account);

    expect($account->portfolioSnapshots()->sole())
        ->total_value->toBe('200.0000')
        ->cash->toBe('200.0000')
        ->invested->toBe('0.0000');
});

test('syncs record at most one snapshot every 15 minutes per account', function () {
    fakeSyncResponses();
    [$account, $otherAccount] = TradingAccount::factory()->count(2)->create();

    SyncTradingAccount::dispatchSync($account);
    $this->travel(14)->minutes();
    $this->travel(59)->seconds();
    SyncTradingAccount::dispatchSync($account);
    SyncTradingAccount::dispatchSync($otherAccount);

    expect($account->portfolioSnapshots()->count())->toBe(1)
        ->and($otherAccount->portfolioSnapshots()->count())->toBe(1);

    $this->travel(1)->seconds();
    SyncTradingAccount::dispatchSync($account);

    expect($account->portfolioSnapshots()->count())->toBe(2);
});

test('the throttle is checked while the account row is locked', function () {
    $account = TradingAccount::factory()->create();
    DB::enableQueryLog();

    app(RecordPortfolioSnapshot::class)->handle($account, snapshotSummary());

    $queries = collect(DB::getQueryLog())->pluck('query');
    $lockIndex = $queries->search(fn (string $query) => str_contains($query, 'for update'));
    $throttleIndex = $queries->search(fn (string $query) => str_contains($query, 'exists') && str_contains($query, 'portfolio_snapshots'));

    expect($lockIndex)->toBeInt()
        ->and($throttleIndex)->toBeGreaterThan($lockIndex);
});

test('the first snapshot becomes the baseline and later snapshots do not replace it', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::sequence()
            ->push(snapshotSummary(200))
            ->push(snapshotSummary(180)),
        'demo.trading212.com/api/v0/equity/positions' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
    ]);
    $account = TradingAccount::factory()->create();

    SyncTradingAccount::dispatchSync($account);
    $this->travel(16)->minutes();
    SyncTradingAccount::dispatchSync($account);

    expect($account->portfolioSnapshots()->count())->toBe(2)
        ->and($account->refresh()->baseline()->total_value)->toBe('200.0000');
});

test('the baseline cannot be overwritten through mass assignment', function () {
    $account = TradingAccount::factory()->create();
    $baseline = PortfolioSnapshot::factory()->for($account)->create();
    $account->forceFill(['baseline_snapshot_id' => $baseline->getKey()])->save();
    $later = PortfolioSnapshot::factory()->for($account)->create();

    $account->update(['baseline_snapshot_id' => $later->getKey()]);

    expect($account->refresh()->baseline_snapshot_id)->toBe($baseline->getKey());
});

test('a baseline snapshot cannot be deleted on its own', function () {
    $account = TradingAccount::factory()->create();
    $baseline = app(RecordPortfolioSnapshot::class)->handle($account, snapshotSummary());

    expect(fn () => $baseline->delete())->toThrow(QueryException::class);
});

test('an account can be deleted together with its baseline snapshot', function () {
    fakeSyncResponses();
    $account = TradingAccount::factory()->create();
    SyncTradingAccount::dispatchSync($account);

    $account->delete();

    expect(PortfolioSnapshot::query()->count())->toBe(0);
});

test('switching the credentials to another Trading 212 account starts a new baseline', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::sequence()
            ->push(snapshotSummary(200, id: 1))
            ->push(snapshotSummary(500, id: 2))
            ->push(snapshotSummary(450, id: 2)),
        'demo.trading212.com/api/v0/equity/positions' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
    ]);
    $account = TradingAccount::factory()->create();
    SyncTradingAccount::dispatchSync($account);
    $oldBaseline = $account->refresh()->baseline();

    $account->update(['api_key' => 'another-account-key']);
    $this->travel(10)->seconds();
    SyncTradingAccount::dispatchSync($account);
    $this->travel(16)->minutes();
    SyncTradingAccount::dispatchSync($account);

    $account->refresh();
    expect($oldBaseline->external_account_id)->toBe(1)
        ->and($account->portfolioSnapshots()->count())->toBe(3)
        ->and($account->external_account_id)->toBe(2)
        ->and($account->baseline()->total_value)->toBe('500.0000')
        ->and($account->baseline()->external_account_id)->toBe(2)
        ->and($account->firstSnapshotToday()->total_value)->toBe('500.0000');
});

test('an invalid summary from a new Trading 212 account still resets the baseline', function () {
    $account = TradingAccount::factory()->create();
    app(RecordPortfolioSnapshot::class)->handle($account, snapshotSummary(200, id: 1));

    expect(fn () => app(RecordPortfolioSnapshot::class)->handle($account, [...snapshotSummary(id: 2), 'totalValue' => 0]))
        ->toThrow(UnexpectedValueException::class);

    $account->refresh();
    expect($account->baseline_snapshot_id)->toBeNull()
        ->and($account->external_account_id)->toBe(2)
        ->and($account->baseline())->toBeNull()
        ->and($account->firstSnapshotToday())->toBeNull();
});

test('the baseline of a previous Trading 212 account is not returned after the account id changes', function () {
    $account = TradingAccount::factory()->create();
    app(RecordPortfolioSnapshot::class)->handle($account, snapshotSummary(200, id: 1));

    $account->update(['external_account_id' => 2]);

    expect($account->refresh()->baselineSnapshot)->not->toBeNull()
        ->and($account->baseline())->toBeNull()
        ->and($account->firstSnapshotToday())->toBeNull();
});

test('a summary with a malformed currency records nothing', function () {
    $account = TradingAccount::factory()->create();

    expect(fn () => app(RecordPortfolioSnapshot::class)->handle($account, [...snapshotSummary(), 'currency' => 'POUNDS']))
        ->toThrow(UnexpectedValueException::class);
    expect(PortfolioSnapshot::query()->count())->toBe(0);
});

test('a rate-limited sync records nothing', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response([], 429, [
        'x-ratelimit-reset' => (string) (now()->getTimestamp() + 120),
    ])]);
    $account = TradingAccount::factory()->create();

    (new SyncTradingAccount($account))->withFakeQueueInteractions()->handle();

    expect(PortfolioSnapshot::query()->count())->toBe(0)
        ->and($account->refresh()->baseline_snapshot_id)->toBeNull();
});

test('a failed sync records nothing', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response(snapshotSummary()),
        'demo.trading212.com/api/v0/equity/positions' => Http::response([], 500),
    ]);
    $account = TradingAccount::factory()->create();

    (new SyncTradingAccount($account))->withFakeQueueInteractions()->handle();

    expect(PortfolioSnapshot::query()->count())->toBe(0);
});

test('an incomplete or implausible summary records nothing', function (array $summary) {
    fakeSyncResponses($summary);
    $account = TradingAccount::factory()->create();
    Log::spy();

    SyncTradingAccount::dispatchSync($account);

    expect(PortfolioSnapshot::query()->count())->toBe(0)
        ->and($account->refresh()->baseline_snapshot_id)->toBeNull()
        ->and($account->last_synced_at)->toEqual(now());
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message) => $message === 'Portfolio snapshot not recorded.');
})->with([
    'no total value' => [['id' => 98765, 'currency' => 'GBP']],
    'zero total value' => [[...snapshotSummary(), 'totalValue' => 0, 'cash' => ['availableToTrade' => 0], 'investments' => ['currentValue' => 0]]],
    'negative total value' => [[...snapshotSummary(), 'totalValue' => -10]],
    'no cash block' => [['id' => 98765, 'totalValue' => 5]],
    'no available cash' => [[...snapshotSummary(), 'cash' => ['reservedForOrders' => 10]]],
    'no investments block' => [[...snapshotSummary(), 'investments' => null]],
    'non-numeric investments' => [[...snapshotSummary(), 'investments' => ['currentValue' => 'n/a']]],
    'negative cash' => [[...snapshotSummary(), 'cash' => ['availableToTrade' => -40.25]]],
    'total off by more than 1%' => [[...snapshotSummary(1000), 'totalValue' => 1011]],
    'total off by more than one pound' => [[...snapshotSummary(50.5), 'totalValue' => 51.51]],
    'amount too large for the column' => [[...snapshotSummary(), 'totalValue' => 1e16, 'investments' => ['currentValue' => 1e16]]],
    'no account id' => [[...snapshotSummary(), 'id' => null]],
]);

test('a summary within the consistency tolerance and without optional cash fields is recorded', function (array $summary, string $cash) {
    fakeSyncResponses($summary);
    $account = TradingAccount::factory()->create();

    SyncTradingAccount::dispatchSync($account);

    expect($account->portfolioSnapshots()->sole()->cash)->toBe($cash);
})->with([
    'reserved and pie cash missing' => [['id' => 98765, 'totalValue' => 100, 'cash' => ['availableToTrade' => 40], 'investments' => ['currentValue' => 60]], '40.0000'],
    'off by just under 1%' => [[...snapshotSummary(1000), 'totalValue' => 1010], '50.5000'],
    'off by exactly one pound' => [[...snapshotSummary(50.5), 'totalValue' => 51.5], '50.5000'],
]);

test('a failure while recording the snapshot does not fail the sync', function () {
    fakeSyncResponses();
    $account = TradingAccount::factory()->create();
    $this->mock(RecordPortfolioSnapshot::class)
        ->shouldReceive('handle')
        ->andThrow(new RuntimeException('numeric field overflow'));
    EventFacade::fake([PortfolioUpdated::class]);
    Log::spy();

    $job = (new SyncTradingAccount($account))->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotFailed();
    expect($account->refresh()->last_synced_at)->toEqual(now());
    EventFacade::assertDispatched(PortfolioUpdated::class);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $context['message'] === 'numeric field overflow');
});

test('the snapshot job is scheduled on weekdays at 08:05 and 16:40 London time', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => $event instanceof CallbackEvent
            && str_contains($event->description ?? '', 'portfolio-snapshots'));

    expect($events->map(fn (Event $event) => [$event->expression, $event->timezone])->values()->all())
        ->toBe([
            ['5 8 * * 1-5', 'Europe/London'],
            ['40 16 * * 1-5', 'Europe/London'],
        ]);

    Queue::fake();
    $events->first()->run(app());

    Queue::assertPushed(SnapshotPortfolios::class);
});

test('the snapshot job times out before the queue would retry it', function () {
    expect((new SnapshotPortfolios)->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
});

test('the scheduled job snapshots every account with fresh data, ignoring the throttle', function () {
    fakeSyncResponses();
    [$first, $second] = TradingAccount::factory()->count(2)->create();
    PortfolioSnapshot::factory()->for($first)->create(['external_account_id' => 98765, 'taken_at' => now()->subMinute()]);

    SnapshotPortfolios::dispatchSync();

    expect($first->portfolioSnapshots()->count())->toBe(2)
        ->and($second->portfolioSnapshots()->sole()->total_value)->toBe('200.5000');
    Http::assertSentCount(2);
});

test('the scheduled job bypasses the cached account summary', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::sequence()
            ->push(snapshotSummary(100))
            ->push(snapshotSummary(200)),
    ]);
    $account = TradingAccount::factory()->create();
    $account->client()->accountSummary();
    app(Trading212RateLimiter::class)->clear($account, 'account.summary');

    SnapshotPortfolios::dispatchSync();

    expect($account->portfolioSnapshots()->sole()->total_value)->toBe('200.0000');
});

test('a failing account does not stop the scheduled job snapshotting the others', function () {
    $failing = TradingAccount::factory()->live()->create();
    $healthy = TradingAccount::factory()->create();
    Http::fake([
        'live.trading212.com/*' => Http::response([], 500),
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response(snapshotSummary()),
    ]);
    Log::spy();

    SnapshotPortfolios::dispatchSync();

    expect($failing->portfolioSnapshots()->count())->toBe(0)
        ->and($healthy->portfolioSnapshots()->count())->toBe(1);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $context['trading_account_id'] === $failing->getKey());
});

test('the scheduled job logs an invalid summary', function () {
    fakeSyncResponses([...snapshotSummary(), 'totalValue' => 0]);
    $account = TradingAccount::factory()->create();
    Log::spy();

    SnapshotPortfolios::dispatchSync();

    expect($account->portfolioSnapshots()->count())->toBe(0);
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $context['trading_account_id'] === $account->getKey()
            && $context['exception'] === UnexpectedValueException::class);
});

test('snapshots are grouped by Europe/London calendar day', function (string $day, string $firstUtc, string $lastUtc) {
    $this->travelTo(CarbonImmutable::parse("{$day} 12:00", 'Europe/London'));
    $account = TradingAccount::factory()->create(['external_account_id' => 98765]);
    $first = CarbonImmutable::parse($firstUtc, 'UTC');
    $last = CarbonImmutable::parse($lastUtc, 'UTC');
    PortfolioSnapshot::factory()->for($account)->create(['taken_at' => $first->subSecond()]);
    PortfolioSnapshot::factory()->for($account)->create(['taken_at' => $last]);
    $firstToday = PortfolioSnapshot::factory()->for($account)->create(['taken_at' => $first]);
    PortfolioSnapshot::factory()->for($account)->create(['taken_at' => $last->addSecond()]);
    PortfolioSnapshot::factory()->create(['taken_at' => $first->addMinute()]);

    expect($account->firstSnapshotToday()?->is($firstToday))->toBeTrue()
        ->and($account->portfolioSnapshots()->takenOnLondonDay()->count())->toBe(2);
})->with([
    'summer (BST)' => ['2026-07-15', '2026-07-14 23:00:00', '2026-07-15 22:59:59'],
    'winter (GMT)' => ['2026-01-15', '2026-01-15 00:00:00', '2026-01-15 23:59:59'],
    'clocks go forward' => ['2026-03-29', '2026-03-29 00:00:00', '2026-03-29 22:59:59'],
    'clocks go back' => ['2026-10-25', '2026-10-24 23:00:00', '2026-10-25 23:59:59'],
]);

test('the first snapshot of the day ignores snapshots of a previous Trading 212 account', function () {
    $account = TradingAccount::factory()->create(['external_account_id' => 2]);
    PortfolioSnapshot::factory()->for($account)->create(['external_account_id' => 1, 'taken_at' => now()->subMinute()]);
    $current = PortfolioSnapshot::factory()->for($account)->create(['external_account_id' => 2]);

    expect($account->firstSnapshotToday()?->is($current))->toBeTrue();
});
