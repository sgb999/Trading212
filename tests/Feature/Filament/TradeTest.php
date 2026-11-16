<?php

use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\TradeOrderStatus;
use App\Filament\Pages\Trade;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use Filament\Forms\Components\Select;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Feature\Filament\FakesTrading212;

beforeEach(function () {
    Sleep::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function orderRequests(): array
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'POST')->all();
}

it('prefills the order ticket from the query string', function () {
    FakesTrading212::fake();
    TradingAccount::factory()->for($this->user)->default()->create();

    Livewire::withQueryParams(['ticker' => 'TSLA_US_EQ', 'side' => 'SELL', 'quantity' => '2'])
        ->test(Trade::class)
        ->assertSchemaStateSet([
            'ticker' => 'TSLA_US_EQ',
            'side' => OrderSide::Sell,
            'quantity' => 2.0,
            'type' => OrderType::Market,
        ])
        ->assertSee('Tesla Inc');
});

it('searches instruments through the API', function () {
    FakesTrading212::fake();
    TradingAccount::factory()->for($this->user)->default()->create();

    $results = Livewire::test(Trade::class)
        ->instance()
        ->getSchema('form')
        ->getComponent(fn ($component): bool => $component instanceof Select && $component->getName() === 'ticker')
        ->getSearchResults('micro');

    expect($results)->toHaveKey('MSFT_US_EQ')
        ->and($results['MSFT_US_EQ'])->toContain('Microsoft Corporation');

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://demo.trading212.com/api/v0/equity/metadata/instruments');
});

it('places a demo market buy immediately through the PlaceOrder action', function () {
    FakesTrading212::fake();
    $account = TradingAccount::factory()->for($this->user)->default()->create();

    Livewire::test(Trade::class)
        ->fillForm([
            'ticker' => 'AAPL_US_EQ',
            'side' => 'BUY',
            'type' => 'MARKET',
            'quantity' => 1.5,
            'extended_hours' => true,
        ])
        ->mountAction('placeOrder')
        ->assertActionNotMounted('placeOrder')
        ->assertHasNoFormErrors()
        ->assertNotified('Order placed');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://demo.trading212.com/api/v0/equity/orders/market'
        && $request['ticker'] === 'AAPL_US_EQ'
        && $request['quantity'] == 1.5
        && $request['extendedHours'] === true);

    $order = TradeOrder::sole();
    expect($order->trading_account_id)->toBe($account->id)
        ->and($order->placedBy->is($this->user))->toBeTrue()
        ->and($order->external_id)->toBe(9001)
        ->and($order->status)->toBe(TradeOrderStatus::Submitted);
});

it('sends sells as a negative quantity', function () {
    FakesTrading212::fake();
    TradingAccount::factory()->for($this->user)->default()->create();

    Livewire::test(Trade::class)
        ->fillForm([
            'ticker' => 'AAPL_US_EQ',
            'side' => 'SELL',
            'type' => 'LIMIT',
            'quantity' => 3,
            'limit_price' => 199.5,
            'time_validity' => 'GOOD_TILL_CANCEL',
        ])
        ->callAction('placeOrder')
        ->assertHasNoFormErrors();

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://demo.trading212.com/api/v0/equity/orders/limit'
        && $request['quantity'] == -3
        && $request['limitPrice'] == 199.5
        && $request['timeValidity'] === 'GOOD_TILL_CANCEL');
});

it('validates prices required by the order type', function (string $type, array $missing) {
    FakesTrading212::fake();
    TradingAccount::factory()->for($this->user)->default()->create();

    Livewire::test(Trade::class)
        ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => $type, 'quantity' => 1])
        ->callAction('placeOrder')
        ->assertHasFormErrors(array_fill_keys($missing, 'required'));

    expect(orderRequests())->toBeEmpty();
})->with([
    'limit' => ['LIMIT', ['limit_price']],
    'stop' => ['STOP', ['stop_price']],
    'stop limit' => ['STOP_LIMIT', ['limit_price', 'stop_price']],
]);

it('rejects non-positive quantities', function () {
    FakesTrading212::fake();
    TradingAccount::factory()->for($this->user)->default()->create();

    Livewire::test(Trade::class)
        ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'SELL', 'type' => 'MARKET', 'quantity' => -2])
        ->callAction('placeOrder')
        ->assertHasFormErrors(['quantity']);

    expect(orderRequests())->toBeEmpty();
});

it('requires an explicit confirmation before placing a live order', function () {
    FakesTrading212::fake('live');
    TradingAccount::factory()->for($this->user)->live()->default()->create(['name' => 'Real ISA']);

    $component = Livewire::test(Trade::class)
        ->assertSee('Live account — real money')
        ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1])
        ->mountAction('placeOrder')
        ->assertActionMounted('placeOrder')
        ->assertMountedActionModalSee('Confirm LIVE order')
        ->assertMountedActionModalSee('REAL-MONEY order to “Real ISA”: Buy 1 × AAPL_US_EQ (Market)');

    expect(orderRequests())->toBeEmpty();

    $component->callMountedAction()->assertNotified('Order placed');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://live.trading212.com/api/v0/equity/orders/market');
});

it('does not place a live order when the confirmation is dismissed', function () {
    FakesTrading212::fake('live');
    TradingAccount::factory()->for($this->user)->live()->default()->create();

    Livewire::test(Trade::class)
        ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1])
        ->mountAction('placeOrder')
        ->unmountAction();

    expect(orderRequests())->toBeEmpty()
        ->and(TradeOrder::count())->toBe(0);
});

it('cannot bypass the live confirmation by calling the submit handler directly', function () {
    FakesTrading212::fake('live');
    TradingAccount::factory()->for($this->user)->live()->default()->create();

    $component = Livewire::test(Trade::class)
        ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1]);

    expect(fn () => $component->call('placeOrder'))->toThrow(Exception::class)
        ->and(orderRequests())->toBeEmpty();
});

it('shows a friendly notification when rate limited', function () {
    FakesTrading212::fake(overrides: [
        'demo.trading212.com/api/v0/equity/orders/market*' => Http::response(['code' => 'TooManyRequests'], 429, [
            'x-ratelimit-remaining' => '0',
            'x-ratelimit-reset' => (string) (now()->getTimestamp() + 42),
        ]),
    ]);
    TradingAccount::factory()->for($this->user)->default()->create();

    Livewire::test(Trade::class)
        ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1])
        ->callAction('placeOrder')
        ->assertNotified('Slow down — rate limit reached');

    expect(TradeOrder::sole()->status)->toBe(TradeOrderStatus::Failed);
});

it('shows the Trading 212 error when the order is rejected', function () {
    FakesTrading212::fake(overrides: [
        'demo.trading212.com/api/v0/equity/orders/market*' => Http::response(['code' => 'InsufficientFunds'], 400),
    ]);
    TradingAccount::factory()->for($this->user)->default()->create();

    Livewire::test(Trade::class)
        ->fillForm(['ticker' => 'AAPL_US_EQ', 'side' => 'BUY', 'type' => 'MARKET', 'quantity' => 1000])
        ->callAction('placeOrder')
        ->assertNotified('Order rejected by Trading 212');

    expect(TradeOrder::sole()->error)->toContain('InsufficientFunds');
});

it('disables ordering without a trading account', function () {
    Livewire::test(Trade::class)
        ->assertSee('No trading account')
        ->assertActionDisabled('placeOrder');
});
