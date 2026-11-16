<?php

use App\Enums\TradeOrderStatus;
use App\Events\PortfolioUpdated;
use App\Jobs\SyncTradingAccount;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->freezeSecond();
});

/**
 * @param  list<array<string, mixed>>  $pendingOrders
 * @param  list<array<string, mixed>>  $history
 */
function coreFakeAccountState(array $pendingOrders = [], array $history = [], float $totalValue = 1000): void
{
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response([
            'id' => 98765,
            'currency' => 'EUR',
            'totalValue' => $totalValue,
        ]),
        'demo.trading212.com/api/v0/equity/positions' => Http::response([
            ['instrument' => ['ticker' => 'AAPL_US_EQ'], 'quantity' => 1],
        ]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response($pendingOrders),
        'demo.trading212.com/api/v0/equity/history/orders*' => Http::response([
            'items' => $history,
            'nextPagePath' => null,
        ]),
    ]);
}

test('it stores account details and broadcasts when the state changed', function () {
    coreFakeAccountState();
    $account = TradingAccount::factory()->create(['currency' => null]);
    Event::fake([PortfolioUpdated::class]);

    SyncTradingAccount::dispatchSync($account);

    expect($account->refresh())
        ->external_account_id->toBe(98765)
        ->currency->toBe('EUR')
        ->last_synced_at->toEqual(now());
    Event::assertDispatched(
        PortfolioUpdated::class,
        fn (PortfolioUpdated $event) => $event->tradingAccount->is($account),
    );
});

test('it does not broadcast when nothing changed since the previous sync', function () {
    coreFakeAccountState();
    $account = TradingAccount::factory()->create();
    Event::fake([PortfolioUpdated::class]);

    SyncTradingAccount::dispatchSync($account);
    $this->travel(10)->seconds();
    SyncTradingAccount::dispatchSync($account);

    Event::assertDispatchedTimes(PortfolioUpdated::class, 1);
    Http::assertSentCount(6);
});

test('it broadcasts again when the state changes', function () {
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::sequence()
            ->push(['id' => 98765, 'totalValue' => 1000])
            ->push(['id' => 98765, 'totalValue' => 1001]),
        'demo.trading212.com/api/v0/equity/positions' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
    ]);
    $account = TradingAccount::factory()->create();
    Event::fake([PortfolioUpdated::class]);

    SyncTradingAccount::dispatchSync($account);
    $this->travel(10)->seconds();
    SyncTradingAccount::dispatchSync($account);

    Event::assertDispatchedTimes(PortfolioUpdated::class, 2);
});

test('it reconciles local orders with pending orders and history', function () {
    coreFakeAccountState(
        pendingOrders: [['id' => 1, 'status' => 'PARTIALLY_FILLED']],
        history: [
            ['order' => ['id' => 2, 'status' => 'FILLED']],
            ['order' => ['id' => 3, 'status' => 'CANCELLED']],
            ['order' => ['id' => 4, 'status' => 'REJECTED']],
        ],
    );
    $account = TradingAccount::factory()->create();
    $orders = collect([1, 2, 3, 4, 5])->mapWithKeys(fn (int $id) => [
        $id => TradeOrder::factory()->for($account)->create(['external_id' => $id, 'external_status' => 'NEW']),
    ]);
    $alreadyCancelled = TradeOrder::factory()->for($account)->create([
        'external_id' => 6,
        'status' => TradeOrderStatus::Cancelled,
    ]);

    SyncTradingAccount::dispatchSync($account);

    expect($orders[1]->refresh())->status->toBe(TradeOrderStatus::Submitted)->external_status->toBe('PARTIALLY_FILLED')
        ->and($orders[2]->refresh())->status->toBe(TradeOrderStatus::Filled)->external_status->toBe('FILLED')
        ->and($orders[3]->refresh())->status->toBe(TradeOrderStatus::Cancelled)
        ->and($orders[4]->refresh())->status->toBe(TradeOrderStatus::Failed)
        ->and($orders[5]->refresh())->status->toBe(TradeOrderStatus::Submitted)->external_status->toBe('NEW')
        ->and($alreadyCancelled->refresh()->status)->toBe(TradeOrderStatus::Cancelled);
    Http::assertSent(fn ($request) => str_contains($request->url(), '/equity/history/orders?limit=50'));
});

test('order history is not requested while all local orders are still pending', function () {
    coreFakeAccountState(pendingOrders: [['id' => 1, 'status' => 'NEW']]);
    $account = TradingAccount::factory()->create();
    TradeOrder::factory()->for($account)->create(['external_id' => 1]);

    SyncTradingAccount::dispatchSync($account);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/history/'));
});

test('it releases itself when Trading 212 is rate limiting', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response([], 429, [
        'x-ratelimit-reset' => (string) (now()->getTimestamp() + 120),
    ])]);
    $account = TradingAccount::factory()->create();

    $job = (new SyncTradingAccount($account))->withFakeQueueInteractions();
    $job->handle();

    $job->assertReleased(delay: 120);
    expect($account->refresh()->last_synced_at)->toBeNull();
});

test('it logs API errors without failing', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response([], 401)]);
    Log::spy();
    $account = TradingAccount::factory()->create();

    $job = (new SyncTradingAccount($account))->withFakeQueueInteractions();
    $job->handle();

    $job->assertNotFailed();
    $job->assertNotReleased();
    expect($account->refresh()->last_synced_at)->toBeNull();
    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context) => $context['status'] === 401);
});

test('only one sync per account can be queued at a time', function () {
    Queue::fake([SyncTradingAccount::class]);
    [$first, $second] = TradingAccount::factory()->count(2)->create();

    SyncTradingAccount::dispatch($first);
    SyncTradingAccount::dispatch($first);
    SyncTradingAccount::dispatch($second);

    Queue::assertPushedTimes(SyncTradingAccount::class, 2);
});
