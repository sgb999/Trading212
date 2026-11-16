<?php

use App\Enums\OrderType;
use App\Enums\TimeValidity;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->freezeTime();
});

test('demo accounts call the demo base URL', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response(['id' => 1])]);
    $account = TradingAccount::factory()->create();

    $account->client()->accountSummary();

    Http::assertSent(fn (Request $request) => $request->url()
        === 'https://demo.trading212.com/api/v0/equity/account/summary');
});

test('live accounts call the live base URL', function () {
    Http::fake(['live.trading212.com/api/v0/equity/account/summary' => Http::response(['id' => 1])]);
    $account = TradingAccount::factory()->live()->create();

    $account->client()->accountSummary();

    Http::assertSent(fn (Request $request) => $request->url()
        === 'https://live.trading212.com/api/v0/equity/account/summary');
});

test('requests authenticate with basic auth of key and secret', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response(['id' => 1])]);
    $account = TradingAccount::factory()->create(['api_key' => 'my-key', 'api_secret' => 'my-secret']);

    $account->client()->accountSummary();

    Http::assertSent(fn (Request $request) => $request->header('Authorization')
        === ['Basic '.base64_encode('my-key:my-secret')]);
});

test('GET responses are cached and fresh bypasses the cache', function () {
    Sleep::fake();
    Sleep::syncWithCarbon();
    Http::fake(['demo.trading212.com/api/v0/equity/positions' => Http::sequence()
        ->push([['instrument' => ['ticker' => 'AAPL_US_EQ'], 'quantity' => 1]])
        ->push([['instrument' => ['ticker' => 'AAPL_US_EQ'], 'quantity' => 2]]),
    ]);
    $client = TradingAccount::factory()->create()->client();

    $first = $client->positions();
    $cached = $client->positions();
    $fresh = $client->positions(fresh: true);

    expect($cached)->toBe($first)
        ->and($fresh[0]['quantity'])->toBe(2);
    Http::assertSentCount(2);
});

test('history pages always contain items and nextPagePath', function (string $method, string $path, mixed $body) {
    Http::fake(["demo.trading212.com/api/v0/equity/history/{$path}*" => Http::response($body)]);
    $client = TradingAccount::factory()->create()->client();

    expect($client->{$method}())->toBe(['items' => [], 'nextPagePath' => null]);
})->with([
    'orders, empty body' => ['orderHistory', 'orders', []],
    'dividends, missing items' => ['dividends', 'dividends', ['nextPagePath' => null]],
    'transactions, null items' => ['transactions', 'transactions', ['items' => null, 'nextPagePath' => 42]],
]);

test('history pages keep valid items and the next page path', function () {
    $order = ['id' => 1, 'ticker' => 'AAPL_US_EQ'];
    Http::fake(['demo.trading212.com/api/v0/equity/history/orders*' => Http::response([
        'items' => [$order, 'not-an-item'],
        'nextPagePath' => '/api/v0/equity/history/orders?cursor=123',
    ])]);

    $page = TradingAccount::factory()->create()->client()->orderHistory();

    expect($page)->toBe([
        'items' => [$order],
        'nextPagePath' => '/api/v0/equity/history/orders?cursor=123',
    ]);
});

test('the response cache is shared by accounts with the same credentials', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/account/summary' => Http::response(['id' => 1])]);
    $first = TradingAccount::factory()->create(['api_key' => 'shared-key']);
    $second = TradingAccount::factory()->create(['api_key' => 'shared-key']);

    $first->client()->accountSummary();
    $second->client()->accountSummary();

    Http::assertSentCount(1);
});

test('placing an order flushes cached trading state', function () {
    Sleep::fake();
    Sleep::syncWithCarbon();
    Http::fake([
        'demo.trading212.com/api/v0/equity/positions' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 10, 'status' => 'NEW']),
    ]);
    $client = TradingAccount::factory()->create()->client();
    $client->positions();

    $client->placeOrder(OrderType::Market, 'AAPL_US_EQ', 1);
    $client->positions();

    Http::assertSentCount(3);
});

test('cancelling an order sends a DELETE and flushes cached trading state', function () {
    Sleep::fake();
    Sleep::syncWithCarbon();
    Http::fake([
        'demo.trading212.com/api/v0/equity/orders' => Http::response([]),
        'demo.trading212.com/api/v0/equity/orders/42' => Http::response(),
    ]);
    $client = TradingAccount::factory()->create()->client();
    $client->pendingOrders();

    $client->cancelOrder(42);
    $client->pendingOrders();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && str_ends_with($request->url(), '/equity/orders/42'));
    Http::assertSentCount(3);
});

test('order payloads match the Trading 212 request schema', function (
    OrderType $type,
    array $arguments,
    string $path,
    array $payload,
) {
    Http::fake(["demo.trading212.com/api/v0/equity/orders/{$path}" => Http::response(['id' => 1])]);
    $client = TradingAccount::factory()->create()->client();

    $client->placeOrder($type, ...$arguments);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), "/equity/orders/{$path}")
        && $request->data() === $payload);
})->with([
    'market buy' => [
        OrderType::Market,
        ['ticker' => 'AAPL_US_EQ', 'quantity' => 1.5, 'extendedHours' => true],
        'market',
        ['ticker' => 'AAPL_US_EQ', 'quantity' => 1.5, 'extendedHours' => true],
    ],
    'market sell' => [
        OrderType::Market,
        ['ticker' => 'AAPL_US_EQ', 'quantity' => -2.0],
        'market',
        ['ticker' => 'AAPL_US_EQ', 'quantity' => -2.0, 'extendedHours' => false],
    ],
    'limit' => [
        OrderType::Limit,
        [
            'ticker' => 'AAPL_US_EQ',
            'quantity' => 1.0,
            'limitPrice' => 100.5,
            'timeValidity' => TimeValidity::GoodTillCancel,
        ],
        'limit',
        ['ticker' => 'AAPL_US_EQ', 'quantity' => 1.0, 'timeValidity' => 'GOOD_TILL_CANCEL', 'limitPrice' => 100.5],
    ],
    'stop' => [
        OrderType::Stop,
        ['ticker' => 'AAPL_US_EQ', 'quantity' => -1.0, 'stopPrice' => 90.0],
        'stop',
        ['ticker' => 'AAPL_US_EQ', 'quantity' => -1.0, 'timeValidity' => 'DAY', 'stopPrice' => 90.0],
    ],
    'stop limit' => [
        OrderType::StopLimit,
        ['ticker' => 'AAPL_US_EQ', 'quantity' => 1.0, 'limitPrice' => 101.0, 'stopPrice' => 100.0],
        'stop_limit',
        [
            'ticker' => 'AAPL_US_EQ',
            'quantity' => 1.0,
            'timeValidity' => 'DAY',
            'limitPrice' => 101.0,
            'stopPrice' => 100.0,
        ],
    ],
]);

test('error responses are mapped to Trading212Exception', function (int $status, string $message) {
    Http::fake([
        'demo.trading212.com/api/v0/equity/account/summary' => Http::response(['message' => 'Bad ticker'], $status),
    ]);
    $client = TradingAccount::factory()->create()->client();

    expect(fn () => $client->accountSummary())
        ->toThrow(fn (Trading212Exception $e) => expect($e->status)->toBe($status)
            ->and($e->getMessage())->toContain($message)
            ->and($e->body)->toBe(['message' => 'Bad ticker']));
})->with([
    'unauthorized' => [401, 'rejected the API credentials'],
    'forbidden' => [403, 'lacks the required scope'],
    'bad request' => [400, 'failed (400): Bad ticker'],
]);

test('a 429 waits for the reset and retries once', function () {
    Sleep::fake();
    Sleep::syncWithCarbon();
    Http::fake(['demo.trading212.com/api/v0/equity/positions' => Http::sequence()
        ->push([], 429, ['x-ratelimit-reset' => (string) (now()->getTimestamp() + 3)])
        ->push([['quantity' => 1]]),
    ]);
    $client = TradingAccount::factory()->create()->client();

    expect($client->positions())->toBe([['quantity' => 1]]);
    Http::assertSentCount(2);
    Sleep::assertSequence([Sleep::for(3)->seconds()]);
});

test('a 429 with a reset beyond the maximum wait throws RateLimitExceededException', function () {
    Sleep::fake();
    Http::fake(['demo.trading212.com/api/v0/equity/positions' => Http::response([], 429, [
        'x-ratelimit-reset' => (string) (now()->getTimestamp() + 60),
    ])]);
    $client = TradingAccount::factory()->create()->client();

    expect(fn () => $client->positions())
        ->toThrow(fn (RateLimitExceededException $e) => expect($e->retryAfter)->toBe(60));
    Http::assertSentCount(1);
});
