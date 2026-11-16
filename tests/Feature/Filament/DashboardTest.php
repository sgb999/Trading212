<?php

use App\Filament\Pages\Portfolio;
use App\Filament\Support\ActiveTradingAccount;
use App\Filament\Widgets\AccountOverview;
use App\Filament\Widgets\OpenPositions;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Filament\FakesTrading212;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = TradingAccount::factory()->for($this->user)->default()->create(['name' => 'Paper']);
    $this->actingAs($this->user);
});

it('shows the account summary stats', function () {
    FakesTrading212::fake();

    Livewire::test(AccountOverview::class)
        ->assertSee('Paper')
        ->assertSee('£10,500.50')
        ->assertSee('£2,500.25')
        ->assertSee('£8,000.50')
        ->assertSee('£500.50')
        ->assertSee('+6.67%')
        ->assertSee('£120.25');
});

it('explains when the summary cannot be loaded', function () {
    Http::fake(['demo.trading212.com/*' => Http::response(['message' => 'nope'], 401)]);

    Livewire::test(AccountOverview::class)
        ->assertSee('Unavailable')
        ->assertSee('rejected the API credentials');
});

it('asks to connect an account when the user has none', function () {
    $this->account->delete();

    Livewire::test(AccountOverview::class)
        ->assertSee('No trading account connected yet');
});

it('lists open positions on the dashboard', function () {
    FakesTrading212::fake();

    Livewire::test(OpenPositions::class)
        ->assertCanSeeTableRecords(['AAPL_US_EQ', 'TSLA_US_EQ'])
        ->assertSee('Apple Inc')
        ->assertSee('+20.00%')
        ->assertSee('-16.67%');
});

it('renders positions with prefilled buy and sell links', function () {
    FakesTrading212::fake();

    Livewire::test(Portfolio::class)
        ->assertCanSeeTableRecords(['AAPL_US_EQ', 'TSLA_US_EQ'])
        ->assertTableActionHasUrl('buyMore', url('/trade?ticker=AAPL_US_EQ&side=BUY'), 'AAPL_US_EQ')
        ->assertTableActionHasUrl('sell', url('/trade?ticker=TSLA_US_EQ&side=SELL&quantity=2'), 'TSLA_US_EQ');
});

it('searches and sorts positions', function () {
    FakesTrading212::fake();

    Livewire::test(Portfolio::class)
        ->searchTable('tesla')
        ->assertCanSeeTableRecords(['TSLA_US_EQ'])
        ->assertCanNotSeeTableRecords(['AAPL_US_EQ'])
        ->searchTable(null)
        ->sortTable('pnl')
        ->assertCanSeeTableRecords(['TSLA_US_EQ', 'AAPL_US_EQ'], inOrder: true);
});

it('reads positions from the selected account only', function () {
    FakesTrading212::fake('live');
    $live = TradingAccount::factory()->for($this->user)->live()->create();
    session([ActiveTradingAccount::SESSION_KEY => $live->id]);

    Livewire::test(Portfolio::class)->assertCanSeeTableRecords(['AAPL_US_EQ']);

    Http::assertSent(fn ($request): bool => str_starts_with(
        $request->url(),
        'https://live.trading212.com/api/v0/equity/positions',
    ));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'demo.trading212.com'));
});

it('subscribes to the account channel when Echo is configured', function () {
    config(['filament.broadcasting.echo' => ['broadcaster' => 'reverb', 'key' => 'test']]);
    FakesTrading212::fake();

    $listeners = Livewire::test(OpenPositions::class)->instance()->getListeners();

    expect($listeners)->toHaveKey("echo-private:trading-accounts.{$this->account->id},.portfolio.updated")
        ->toHaveKey("echo-private:trading-accounts.{$this->account->id},.order.placed");
});
