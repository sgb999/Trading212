<?php

use App\Enums\TradeOrderStatus;
use App\Filament\Pages\History;
use App\Filament\Pages\PendingOrders;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\Feature\Filament\FakesTrading212;

beforeEach(function () {
    Sleep::fake();
    $this->user = User::factory()->create();
    $this->account = TradingAccount::factory()->for($this->user)->default()->create();
    $this->actingAs($this->user);
});

it('lists pending orders', function () {
    FakesTrading212::fake();

    Livewire::test(PendingOrders::class)
        ->assertCanSeeTableRecords([555])
        ->assertSee('MSFT_US_EQ')
        ->assertSee('$300.50');
});

it('cancels a pending order through the CancelOrder action', function () {
    FakesTrading212::fake();
    $local = TradeOrder::factory()->for($this->account)->create(['external_id' => 555]);

    Livewire::test(PendingOrders::class)
        ->callTableAction('cancel', 555)
        ->assertNotified('Order #555 cancelled');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://demo.trading212.com/api/v0/equity/orders/555');

    expect($local->refresh()->status)->toBe(TradeOrderStatus::Cancelled);
});

it('reports a failed cancellation', function () {
    FakesTrading212::fake(overrides: [
        'demo.trading212.com/api/v0/equity/orders/555' => Http::response(['code' => 'OrderNotFound'], 404),
    ]);

    Livewire::test(PendingOrders::class)
        ->callTableAction('cancel', 555)
        ->assertNotified('Could not cancel order');
});

it('shows order history and loads more pages using the cursor', function () {
    FakesTrading212::fake();

    $component = Livewire::test(History::class)
        ->assertSet('activeTab', 'orders')
        ->assertSet('nextCursor', '1760346100000')
        ->assertSee('AAPL_US_EQ')
        ->assertSee('Load more');

    expect($component->get('items'))->toHaveCount(1);

    $component->call('loadMore');

    expect($component->get('items'))->toHaveCount(2);

    Http::assertSent(fn (Request $request): bool => str_starts_with(
        $request->url(),
        'https://demo.trading212.com/api/v0/equity/history/orders',
    )
        && ($request->data()['cursor'] ?? null) === '1760346100000');
});

it('switches between dividends and transactions tabs', function () {
    FakesTrading212::fake();

    Livewire::test(History::class)
        ->call('setTab', 'dividends')
        ->assertSet('activeTab', 'dividends')
        ->assertSee('DIV-1')
        ->assertSet('nextCursor', null)
        ->assertDontSee('Load more')
        ->call('setTab', 'transactions')
        ->assertSee('DEPOSIT')
        ->assertSee('TX-REF-1');
});

it('ignores unknown history tabs', function () {
    FakesTrading212::fake();

    Livewire::test(History::class)
        ->call('setTab', 'secrets')
        ->assertSet('activeTab', 'orders');
});

it('extracts the cursor from nextPagePath', function (?string $path, ?string $cursor) {
    expect(History::cursorFrom($path))->toBe($cursor);
})->with([
    ['/api/v0/equity/history/orders?limit=2&cursor=1760346100000', '1760346100000'],
    ['/api/v0/equity/history/transactions?cursor=abc-123&time=2026-01-01', 'abc-123'],
    ['/api/v0/equity/history/orders?limit=2', null],
    [null, null],
]);
