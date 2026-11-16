<?php

use App\Jobs\SnapshotPortfolios;
use App\Jobs\SyncTradingAccount;
use App\Models\PortfolioSnapshot;
use App\Models\TradingAccount;
use App\Services\Trading212\Trading212RateLimiter;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->freezeSecond();
});

/**
 * @return array<string, mixed>
 */
function snapshotSummary(float $totalValue = 200.5): array
{
    return [
        'id' => 98765,
        'currency' => 'GBP',
        'totalValue' => $totalValue,
        'cash' => ['availableToTrade' => 40.25, 'reservedForOrders' => 10, 'inPies' => 0.25],
        'investments' => ['currentValue' => 150, 'totalCost' => 160],
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
        ->taken_at->toEqual(now());
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
        ->and($account->refresh()->baselineSnapshot->total_value)->toBe('200.0000');
});

test('the baseline cannot be overwritten through mass assignment', function () {
    $account = TradingAccount::factory()->create();
    $baseline = PortfolioSnapshot::factory()->for($account)->create();
    $account->forceFill(['baseline_snapshot_id' => $baseline->getKey()])->save();
    $later = PortfolioSnapshot::factory()->for($account)->create();

    $account->update(['baseline_snapshot_id' => $later->getKey()]);

    expect($account->refresh()->baseline_snapshot_id)->toBe($baseline->getKey());
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

test('a summary without a total value records nothing', function () {
    fakeSyncResponses(['id' => 98765, 'currency' => 'GBP']);
    $account = TradingAccount::factory()->create();

    SyncTradingAccount::dispatchSync($account);

    expect(PortfolioSnapshot::query()->count())->toBe(0)
        ->and($account->refresh()->baseline_snapshot_id)->toBeNull();
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

test('the scheduled job snapshots every account with fresh data, ignoring the throttle', function () {
    fakeSyncResponses();
    [$first, $second] = TradingAccount::factory()->count(2)->create();
    PortfolioSnapshot::factory()->for($first)->create(['taken_at' => now()->subMinute()]);

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

test('the first snapshot of the London day is found across the UTC midnight boundary', function () {
    $this->travelTo(now('Europe/London')->setDate(2026, 7, 15)->setTime(12, 0));
    $account = TradingAccount::factory()->create();
    PortfolioSnapshot::factory()->for($account)->create(['taken_at' => '2026-07-14 22:59:59']);
    $firstToday = PortfolioSnapshot::factory()->for($account)->create(['taken_at' => '2026-07-14 23:00:00']);
    PortfolioSnapshot::factory()->for($account)->create(['taken_at' => '2026-07-15 08:00:00']);
    PortfolioSnapshot::factory()->create(['taken_at' => '2026-07-14 23:30:00']);

    expect($account->firstSnapshotToday()?->is($firstToday))->toBeTrue();
});

test('an account can be deleted together with its baseline snapshot', function () {
    fakeSyncResponses();
    $account = TradingAccount::factory()->create();
    SyncTradingAccount::dispatchSync($account);

    $account->delete();

    expect(PortfolioSnapshot::query()->count())->toBe(0);
});
