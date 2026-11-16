<?php

use App\Actions\PlaceOrder;
use App\Actions\ReconcileUnknownOrders;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TradeOrderStatus;
use App\Events\OrderPlaced;
use App\Exceptions\AgentNotAuthorizedException;
use App\Filament\Pages\Trade;
use App\Jobs\SyncTradingAccount;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\OrderOutcomeUnknownException;
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
            ->toThrow(function (OrderOutcomeUnknownException $e): void {
                expect($e->getMessage())->toContain('Operation timed out')
                    ->and($e->getPrevious())->toBeInstanceOf(Trading212Exception::class);
            });

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
            ->assertNotified('Order blocked: previous order unresolved');

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    });

    test('the order is saved as sending before the request is sent', function () {
        $account = TradingAccount::factory()->create();
        $statusWhileSending = null;
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => function () use (&$statusWhileSending) {
            $statusWhileSending = TradeOrder::query()->sole()->status;

            return Http::response(['id' => 42, 'status' => 'NEW']);
        }]);

        $tradeOrder = app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user);

        expect($statusWhileSending)->toBe(TradeOrderStatus::Sending)
            ->and(TradeOrder::query()->sole())
            ->getKey()->toBe($tradeOrder->getKey())
            ->status->toBe(TradeOrderStatus::Submitted)
            ->external_id->toBe(42);
    });

    test('an agent order denied by its role writes no order', function () {
        Http::fake();
        $account = TradingAccount::factory()->create();
        $agent = unknownOutcomeAgentFor($account, ['max_order_quantity' => 1]);

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(quantity: 5), $agent))
            ->toThrow(AgentNotAuthorizedException::class, 'exceeds the role');

        expect(TradeOrder::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    test('an order still being sent counts toward the daily limit and blocks its ticker', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 1, 'status' => 'NEW'])]);
        $account = TradingAccount::factory()->create();
        $limitedAgent = unknownOutcomeAgentFor($account, ['max_orders_per_day' => 1]);
        unknownOrderFor($account, ['status' => TradeOrderStatus::Sending, 'ticker' => 'MSFT_US_EQ'])
            ->placedBy()->associate($limitedAgent)->save();

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder('AAPL_US_EQ'), $limitedAgent))
            ->toThrow(AgentNotAuthorizedException::class, 'Daily order limit of 1 reached.')
            ->and(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder('MSFT_US_EQ'), $account->user))
            ->toThrow(UnresolvedOrderException::class, 'still being sent');

        Http::assertNothingSent();
    });

    test('a success response without an order id is kept unknown and reported as an unknown outcome', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['status' => 'NEW'])]);
        $account = TradingAccount::factory()->create();
        Event::fake([OrderPlaced::class]);

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user))
            ->toThrow(OrderOutcomeUnknownException::class, 'returned no order id');

        expect(TradeOrder::query()->sole())
            ->status->toBe(TradeOrderStatus::Unknown)
            ->external_id->toBeNull()
            ->error->toContain('returned no order id');
        Event::assertDispatched(OrderPlaced::class);
    });

    test('an unknown order that expires as failed no longer counts toward the daily limit', function () {
        $this->travelTo(now()->setTime(12, 0));
        unknownOutcomeFakeSync();
        $account = TradingAccount::factory()->create();
        $agent = unknownOutcomeAgentFor($account, ['max_orders_per_day' => 1]);
        unknownOrderFor($account, ['ticker' => 'MSFT_US_EQ', 'created_at' => now()->subMinutes(31)])
            ->placedBy()->associate($agent)->save();

        SyncTradingAccount::dispatchSync($account);
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 77, 'status' => 'NEW'])]);
        $tradeOrder = app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder('AAPL_US_EQ'), $agent);

        expect($tradeOrder->status)->toBe(TradeOrderStatus::Submitted)
            ->and(TradeOrder::query()->where('ticker', 'MSFT_US_EQ')->sole()->status)->toBe(TradeOrderStatus::Failed);
    });

    test('the trade page warns that a timed-out order may have been placed', function () {
        FakesTrading212::fake(overrides: [
            'demo.trading212.com/api/v0/equity/orders/market*' => fn () => throw new ConnectionException('timed out'),
        ]);
        $account = TradingAccount::factory()->default()->create();
        $this->actingAs($account->user);

        Livewire::test(Trade::class)
            ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1])
            ->callAction('placeOrder')
            ->assertNotified('Order outcome unknown');

        expect(TradeOrder::query()->sole()->status)->toBe(TradeOrderStatus::Unknown);
    });

    test('the trade page warns when a success response has no order id', function () {
        FakesTrading212::fake(overrides: [
            'demo.trading212.com/api/v0/equity/orders/market*' => Http::response(['status' => 'NEW']),
        ]);
        $account = TradingAccount::factory()->default()->create();
        $this->actingAs($account->user);

        Livewire::test(Trade::class)
            ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1])
            ->callAction('placeOrder')
            ->assertNotified('Order outcome unknown');

        expect(TradeOrder::query()->sole()->status)->toBe(TradeOrderStatus::Unknown);
    });

    test('a request that times out after the sync matched its order keeps the sync outcome', function () {
        config(['trading212.timeout' => 5, 'trading212.max_wait_seconds' => 5]);
        $account = TradingAccount::factory()->create();
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => function () use ($account) {
            $remote = unknownOutcomeRemoteOrder(2101, ['quantity' => 1]);
            $this->travel(21)->seconds();
            app(ReconcileUnknownOrders::class)->handle($account, $account->client(), [$remote]);

            throw new ConnectionException('timed out');
        }]);

        expect(fn () => app(PlaceOrder::class)->handle($account, unknownOutcomeMarketOrder(), $account->user))
            ->toThrow(OrderOutcomeUnknownException::class);

        expect(TradeOrder::query()->sole())
            ->status->toBe(TradeOrderStatus::Submitted)
            ->external_id->toBe(2101);
    });
});

describe('placing an order through MCP', function () {
    test('a timeout records the order as unknown and tells the agent not to retry', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => fn () => throw new ConnectionException('timed out')]);
        $account = TradingAccount::factory()->create();
        $agent = unknownOutcomeAgentFor($account);
        app(CurrentAgent::class)->set($agent);

        TradingServer::tool(PlaceOrderTool::class, [
            'ticker' => 'AAPL_US_EQ',
            'type' => 'MARKET',
            'side' => 'BUY',
            'quantity' => 1,
        ])
            ->assertHasErrors(['outcome of the AAPL_US_EQ order is unknown', 'may have been placed', 'next account sync'])
            ->assertStructuredContentPath('error', 'order_outcome_unknown')
            ->assertStructuredContentPath('local_id', TradeOrder::query()->sole()->getKey());

        expect(TradeOrder::query()->sole())
            ->status->toBe(TradeOrderStatus::Unknown)
            ->placed_by_id->toBe($agent->getKey());
        $this->assertDatabaseHas(AgentActivityLog::class, [
            'tool' => 'place-order',
            'outcome' => AgentActivityLog::OUTCOME_ERROR,
        ]);
    });

    test('a success response without an order id tells the agent the outcome is unknown', function () {
        Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['status' => 'NEW'])]);
        $account = TradingAccount::factory()->create();
        app(CurrentAgent::class)->set(unknownOutcomeAgentFor($account));

        TradingServer::tool(PlaceOrderTool::class, [
            'ticker' => 'AAPL_US_EQ',
            'type' => 'MARKET',
            'side' => 'BUY',
            'quantity' => 1,
        ])
            ->assertHasErrors(['returned no order id', 'may have been placed'])
            ->assertStructuredContentPath('error', 'order_outcome_unknown')
            ->assertStructuredContentPath('local_id', TradeOrder::query()->sole()->getKey());

        expect(TradeOrder::query()->sole()->status)->toBe(TradeOrderStatus::Unknown);
    });

    test('a quantity with more than 8 decimal places is rejected before sending', function () {
        Http::fake();
        $account = TradingAccount::factory()->create();
        app(CurrentAgent::class)->set(unknownOutcomeAgentFor($account));

        TradingServer::tool(PlaceOrderTool::class, [
            'ticker' => 'AAPL_US_EQ',
            'type' => 'MARKET',
            'side' => 'BUY',
            'quantity' => '1.123456789',
        ])->assertHasErrors(['quantity']);

        Http::assertNothingSent();
        expect(TradeOrder::query()->count())->toBe(0);
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

    test('of two matching remote orders the one created closest to the local order wins', function () {
        unknownOutcomeFakeSync(historyOrders: [
            unknownOutcomeRemoteOrder(1301, ['createdAt' => now()->addSeconds(40)->toIso8601String()]),
            unknownOutcomeRemoteOrder(1302, ['createdAt' => now()->addSeconds(10)->toIso8601String()]),
        ]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->external_id)->toBe(1302);
    });

    test('remote orders with another type or placed outside the API are not matched', function (array $attributes) {
        unknownOutcomeFakeSync(historyOrders: [unknownOutcomeRemoteOrder(1401, $attributes)]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->external_id)->toBeNull();
    })->with([
        'other type' => [['type' => 'LIMIT']],
        'no type' => [['type' => null]],
        'placed on the web' => [['initiatedFrom' => 'WEB']],
    ]);

    test('a remote order initiated from the API is matched', function () {
        unknownOutcomeFakeSync(historyOrders: [unknownOutcomeRemoteOrder(1501, ['initiatedFrom' => 'API'])]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->external_id)->toBe(1501);
    });

    test('a quantity Trading 212 rounded slightly still matches', function () {
        unknownOutcomeFakeSync(historyOrders: [unknownOutcomeRemoteOrder(1601, ['quantity' => 0.1234])]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, ['quantity' => 0.12345678]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->external_id)->toBe(1601);
    });

    test('the match window covers the configured request time plus clock skew', function (int $secondsAfter, bool $matches) {
        config(['trading212.timeout' => 5, 'trading212.max_wait_seconds' => 5]);
        unknownOutcomeFakeSync(historyOrders: [
            unknownOutcomeRemoteOrder(1701, ['createdAt' => now()->addSeconds($secondsAfter)->toIso8601String()]),
        ]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->external_id)->toBe($matches ? 1701 : null);
    })->with([
        'skew before the local row' => [-60, true],
        'before the local row beyond skew' => [-61, false],
        'at the end of the request time plus skew' => [80, true],
        'after the request time plus skew' => [81, false],
    ]);

    test('an order left sending after its request ended is resolved like an unknown order', function () {
        unknownOutcomeFakeSync(pendingOrders: [unknownOutcomeRemoteOrder(1801)]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, [
            'status' => TradeOrderStatus::Sending,
            'created_at' => now()->subSeconds(30),
        ]);
        config(['trading212.timeout' => 5, 'trading212.max_wait_seconds' => 5]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh())
            ->external_id->toBe(1801)
            ->status->toBe(TradeOrderStatus::Submitted);
    });

    test('an unresolved order that already has an external id is resolved by that id', function (array $pending, array $history, TradeOrderStatus $status) {
        unknownOutcomeFakeSync(pendingOrders: $pending, historyOrders: $history);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, ['external_id' => 2201, 'ticker' => 'MSFT_US_EQ']);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh())
            ->external_id->toBe(2201)
            ->status->toBe($status);
    })->with([
        'still pending' => fn () => [[unknownOutcomeRemoteOrder(2201, ['ticker' => 'MSFT_US_EQ'])], [], TradeOrderStatus::Submitted],
        'filled in the history' => fn () => [[], [unknownOutcomeRemoteOrder(2201, ['ticker' => 'MSFT_US_EQ', 'status' => 'FILLED'])], TradeOrderStatus::Filled],
        'not found remotely' => fn () => [[], [], TradeOrderStatus::Submitted],
    ]);

    test('an order whose request may still be in flight is left sending', function () {
        unknownOutcomeFakeSync(pendingOrders: [unknownOutcomeRemoteOrder(1901)]);
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, ['status' => TradeOrderStatus::Sending]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh())
            ->external_id->toBeNull()
            ->status->toBe(TradeOrderStatus::Sending);
    });

    test('an order left sending with no match becomes failed after 30 minutes', function () {
        unknownOutcomeFakeSync();
        $account = TradingAccount::factory()->create();
        $order = unknownOrderFor($account, [
            'status' => TradeOrderStatus::Sending,
            'created_at' => now()->subMinutes(31),
        ]);

        SyncTradingAccount::dispatchSync($account);

        expect($order->refresh()->status)->toBe(TradeOrderStatus::Failed);
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
