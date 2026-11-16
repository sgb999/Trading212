<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TradeOrderStatus;
use App\Events\OrderPlaced;
use App\Exceptions\AgentNotAuthorizedException;
use App\Filament\Pages\Trade;
use App\Jobs\SyncTradingAccount;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Exceptions\UnresolvedOrderException;
use App\Services\Trading212\Trading212RateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Feature\Filament\FakesTrading212;

beforeEach(function () {
    Http::preventStrayRequests();
    Sleep::fake();
    $this->freezeSecond();
});

/**
 * @param  array<string, mixed>  $roleAttributes
 */
function unknownOutcomeAgentFor(TradingAccount $account, array $roleAttributes = []): AiAgent
{
    $role = Role::factory()->withPermissions(Permission::cases())->create($roleAttributes);

    $agent = AiAgent::factory()->for($role)->for($account->user)->create(['is_active' => true]);
    $agent->tradingAccounts()->attach($account);

    return $agent;
}

function unknownOutcomeMarketOrder(
    string $ticker = 'AAPL_US_EQ',
    float $quantity = 1,
    OrderSide $side = OrderSide::Buy,
): OrderRequest {
    return new OrderRequest(ticker: $ticker, type: OrderType::Market, side: $side, quantity: $quantity);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function unknownOrderFor(TradingAccount $account, array $attributes = []): TradeOrder
{
    return TradeOrder::factory()->for($account)->create([
        'external_id' => null,
        'external_status' => null,
        'status' => TradeOrderStatus::Unknown,
        'ticker' => 'AAPL_US_EQ',
        'side' => OrderSide::Buy,
        'quantity' => 2,
        ...$attributes,
    ]);
}

/**
 * A remote Trading 212 order as returned by `GET /equity/orders` and inside history items.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<string, mixed>
 */
function unknownOutcomeRemoteOrder(int $id, array $attributes = []): array
{
    return [
        'id' => $id,
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'BUY',
        'quantity' => 2,
        'status' => 'NEW',
        'createdAt' => now()->toIso8601String(),
        ...$attributes,
    ];
}

/**
 * @param  list<array<string, mixed>>  $pendingOrders
 * @param  list<array<string, mixed>>  $historyOrders
 */
function unknownOutcomeFakeSync(array $pendingOrders = [], array $historyOrders = [], mixed $historyResponse = null): void
{
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response(['id' => 98765, 'currency' => 'GBP']),
        'demo.trading212.com/api/v0/equity/positions' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders' => Http::response($pendingOrders),
        'demo.trading212.com/api/v0/equity/history/orders*' => $historyResponse ?? Http::response([
            'items' => array_map(fn (array $order): array => ['order' => $order], $historyOrders),
            'nextPagePath' => null,
        ]),
    ]);
}

describe('placing an order', function () {
    test('a timeout on the order request saves the order as unknown and rethrows', function () {
        Http::fake([
            'demo.trading212.com/api/v0/equity/orders/market' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 10001 milliseconds',
            ),
        ]);
        $account = TradingAccount::factory()->create();
        Event::fake([OrderPlaced::class]);

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user))
            ->toThrow(Trading212Exception::class, 'Operation timed out');

        expect(TradeOrder::query()->sole())
            ->status->toBe(TradeOrderStatus::Unknown)
            ->external_id->toBeNull()
            ->error->toContain('Could not reach Trading 212');
        Event::assertNotDispatched(OrderPlaced::class);
    });

    test('a connection error on the order request saves the order as unknown', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::failedConnection()]);
        $account = TradingAccount::factory()->create();

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user))
            ->toThrow(Trading212Exception::class);

        expect(TradeOrder::query()->sole()->status)->toBe(TradeOrderStatus::Unknown);
    });

    test('a 5xx gateway error saves the order as unknown', function (int $status) {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['message' => 'Gateway'], $status)]);
        $account = TradingAccount::factory()->create();

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user))
            ->toThrow(Trading212Exception::class);

        expect(TradeOrder::query()->sole()->status)->toBe(TradeOrderStatus::Unknown);
    })->with([500, 502, 503, 504]);

    test('a 4xx rejection still saves the order as failed', function (int $status) {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['message' => 'No'], $status)]);
        $account = TradingAccount::factory()->create();

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user))
            ->toThrow(Trading212Exception::class);

        expect(TradeOrder::query()->sole()->status)->toBe(TradeOrderStatus::Failed);
    })->with([400, 401, 403, 404, 408, 422]);

    test('a 429 response saves the order as failed', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response([], 429, [
            'x-ratelimit-reset' => (string) (now()->getTimestamp() + 120),
        ])]);
        $account = TradingAccount::factory()->create();

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user))
            ->toThrow(RateLimitExceededException::class);

        expect(TradeOrder::query()->sole()->status)->toBe(TradeOrderStatus::Failed);
    });

    test('a rate-limit wait that runs out before sending saves the order as failed', function () {
        Http::fake();
        $account = TradingAccount::factory()->create();
        app(Trading212RateLimiter::class)->blockUntil($account, 'orders.market', now()->getTimestamp() + 120);

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user))
            ->toThrow(RateLimitExceededException::class);

        expect(TradeOrder::query()->sole()->status)->toBe(TradeOrderStatus::Failed);
        Http::assertNothingSent();
    });

    test('unknown orders count toward the daily order limit', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 1, 'status' => 'NEW'])]);
        $account = TradingAccount::factory()->create();
        $agent = unknownOutcomeAgentFor($account, ['max_orders_per_day' => 1]);
        unknownOrderFor($account, ['ticker' => 'MSFT_US_EQ'])->placedBy()->associate($agent)->save();

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder('AAPL_US_EQ'), $agent))
            ->toThrow(AgentNotAuthorizedException::class, 'Daily order limit of 1 reached.');

        Http::assertNothingSent();
    });

    test('an agent order on a ticker with an unknown order is denied, whatever the case', function (string $ticker) {
        Http::fake();
        $account = TradingAccount::factory()->create();
        $agent = unknownOutcomeAgentFor($account);
        unknownOrderFor($account, ['ticker' => 'AAPL_US_EQ']);

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder($ticker), $agent))
            ->toThrow(AgentNotAuthorizedException::class, 'unknown outcome');

        Http::assertNothingSent();
        expect(TradeOrder::query()->count())->toBe(1);
    })->with(['AAPL_US_EQ', 'aapl_us_eq', 'Aapl_US_EQ']);

    test('an unknown order blocks only its own ticker on its own account', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 7, 'status' => 'NEW'])]);
        $account = TradingAccount::factory()->create();
        $agent = unknownOutcomeAgentFor($account);
        unknownOrderFor($account, ['ticker' => 'AAPL_US_EQ']);
        unknownOrderFor(TradingAccount::factory()->create(), ['ticker' => 'MSFT_US_EQ']);

        $tradeOrder = app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder('MSFT_US_EQ'), $agent);

        expect($tradeOrder->status)->toBe(TradeOrderStatus::Submitted);
    });

    test('a resolved order no longer blocks its ticker', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 8, 'status' => 'NEW'])]);
        $account = TradingAccount::factory()->create();
        unknownOrderFor($account, ['status' => TradeOrderStatus::Failed]);

        $tradeOrder = app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user);

        expect($tradeOrder->status)->toBe(TradeOrderStatus::Submitted);
    });

    test('a user order on a ticker with an unknown order is refused before sending', function () {
        Http::fake();
        $account = TradingAccount::factory()->create();
        unknownOrderFor($account);

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder('aapl_us_eq'), $account->user))
            ->toThrow(UnresolvedOrderException::class, 'is being reconciled');

        Http::assertNothingSent();
        expect(TradeOrder::query()->count())->toBe(1);
    });

    test('the trade page shows the refusal as an error', function () {
        FakesTrading212::fake();
        $account = TradingAccount::factory()->default()->create();
        $this->actingAs($account->user);
        unknownOrderFor($account);

        Livewire::test(Trade::class)
            ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1])
            ->mountAction('placeOrder')
            ->assertNotified('Order rejected by Trading 212');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    });
});

describe('syncing unknown orders', function () {
    test('an unknown order is resolved from the pending orders', function () {
        $pending = unknownOutcomeRemoteOrder(501, ['createdAt' => now()->addSeconds(20)->toIso8601String()]);
        unknownOutcomeFakeSync(pendingOrders: [$pending]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh())
            ->external_id->toBe(501)
            ->external_status->toBe('NEW')
            ->status->toBe(TradeOrderStatus::Submitted)
            ->response->toEqual($pending);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/history/'));
    });

    test('an unknown order is resolved from the order history', function (string $remoteStatus, TradeOrderStatus $status) {
        unknownOutcomeFakeSync(historyOrders: [unknownOutcomeRemoteOrder(601, ['status' => $remoteStatus])]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh())
            ->external_id->toBe(601)
            ->external_status->toBe($remoteStatus)
            ->status->toBe($status);
    })->with([
        ['FILLED', TradeOrderStatus::Filled],
        ['CANCELLED', TradeOrderStatus::Cancelled],
        ['REJECTED', TradeOrderStatus::Failed],
    ]);

    test('a sell matches only a remote order with a negative or SELL quantity', function () {
        unknownOutcomeFakeSync(historyOrders: [
            unknownOutcomeRemoteOrder(701, ['side' => 'BUY', 'quantity' => 2]),
            unknownOutcomeRemoteOrder(702, ['side' => null, 'quantity' => -2]),
        ]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, ['side' => OrderSide::Sell]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->external_id)->toBe(702);
    });

    test('remote orders with another ticker, quantity or time are not matched', function (array $attributes) {
        unknownOutcomeFakeSync(historyOrders: [unknownOutcomeRemoteOrder(801, $attributes)]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh())
            ->status->toBe(TradeOrderStatus::Unknown)
            ->external_id->toBeNull();
    })->with([
        'other ticker' => [['ticker' => 'MSFT_US_EQ']],
        'other quantity' => [['quantity' => 2.5]],
        'other side' => [['side' => 'SELL']],
        'too early' => [['createdAt' => now()->subMinutes(2)->toIso8601String()]],
        'too late' => [['createdAt' => now()->addMinutes(6)->toIso8601String()]],
    ]);

    test('one remote order is never matched to two local orders', function () {
        unknownOutcomeFakeSync(historyOrders: [unknownOutcomeRemoteOrder(901, ['status' => 'FILLED'])]);
        $account = TradingAccount::factory()->create();
        $first = unknownOrderFor($account);
        $second = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($first->refresh())->external_id->toBe(901)->status->toBe(TradeOrderStatus::Filled)
            ->and($second->refresh())->external_id->toBeNull()->status->toBe(TradeOrderStatus::Unknown);
    });

    test('a remote order whose id is already stored on another order is not reused', function () {
        unknownOutcomeFakeSync(
            pendingOrders: [unknownOutcomeRemoteOrder(1001)],
            historyOrders: [unknownOutcomeRemoteOrder(1002, ['status' => 'FILLED'])],
        );
        $account = TradingAccount::factory()->create();
        TradeOrder::factory()->for($account)->create(['external_id' => 1001, 'ticker' => 'AAPL_US_EQ']);
        TradeOrder::factory()->for($account)->create([
            'external_id' => 1002,
            'ticker' => 'AAPL_US_EQ',
            'status' => TradeOrderStatus::Filled,
        ]);
        $order = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh())
            ->status->toBe(TradeOrderStatus::Unknown)
            ->external_id->toBeNull();
    });

    test('an unmatched unknown order becomes failed after 30 minutes', function () {
        unknownOutcomeFakeSync(historyOrders: [unknownOutcomeRemoteOrder(1101, ['ticker' => 'MSFT_US_EQ'])]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, ['created_at' => now()->subMinutes(31)]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh())
            ->status->toBe(TradeOrderStatus::Failed)
            ->external_id->toBeNull()
            ->error->toContain('no matching order was found');
    });

    test('an unmatched unknown order younger than 30 minutes stays unknown', function () {
        unknownOutcomeFakeSync();
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, ['created_at' => now()->subMinutes(29)]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->status)->toBe(TradeOrderStatus::Unknown);
    });

    test('an old unknown order stays unknown when the history page does not reach back to it', function () {
        unknownOutcomeFakeSync(historyResponse: Http::response([
            'items' => [['order' => unknownOutcomeRemoteOrder(1201, ['ticker' => 'MSFT_US_EQ'])]],
            'nextPagePath' => '/api/v0/equity/history/orders?cursor=1201&limit=50',
        ]));
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, ['created_at' => now()->subHour()]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->status)->toBe(TradeOrderStatus::Unknown);
    });

    test('a failed history lookup leaves an old unknown order unknown', function () {
        unknownOutcomeFakeSync(historyResponse: Http::response(['message' => 'Down'], 503));
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, ['created_at' => now()->subHour()]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->status)->toBe(TradeOrderStatus::Unknown);
    });

    test('a rate-limited history lookup leaves an old unknown order unknown', function () {
        unknownOutcomeFakeSync();
        $account = TradingAccount::factory()->create();
        app(Trading212RateLimiter::class)->blockUntil($account, 'history.orders', now()->getTimestamp() + 120);
        $order = unknownOrderFor($account, ['created_at' => now()->subHour()]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->status)->toBe(TradeOrderStatus::Unknown);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/history/'));
    });
});
