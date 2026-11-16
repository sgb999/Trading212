<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Support\ActiveTradingAccount;
use App\Livewire\TradingAccountSwitcher;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Feature\Filament\FakesTrading212;

it('redirects guests from the root to the login page', function () {
    $this->get('/')->assertRedirect('/login');
});

it('does not offer registration', function () {
    expect(Route::has('filament.admin.auth.register'))->toBeFalse();

    $this->get('/login')->assertOk()->assertDontSee('Sign up');
});

it('serves the dashboard at the site root with the brand and navigation groups', function () {
    FakesTrading212::fake();
    $user = User::factory()->create();
    TradingAccount::factory()->for($user)->default()->create();

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('Trading Platform')
        ->assertSee('Trading')
        ->assertSee('AI')
        ->assertSee('Settings')
        ->assertSee('Portfolio')
        ->assertSee('Order log')
        ->assertDontSee('filamentphp.com');

    expect(Dashboard::getUrl())->toBe(url('/'));
});

it('defaults the active account to the user default account', function () {
    $user = User::factory()->create();
    TradingAccount::factory()->for($user)->create(['name' => 'Second']);
    $default = TradingAccount::factory()->for($user)->default()->create(['name' => 'Main']);

    $this->actingAs($user);

    expect(ActiveTradingAccount::get()->is($default))->toBeTrue();
});

it('switches the active account and labels live accounts', function () {
    $user = User::factory()->create();
    TradingAccount::factory()->for($user)->default()->create(['name' => 'Paper']);
    $live = TradingAccount::factory()->for($user)->live()->create(['name' => 'Real ISA']);

    $this->actingAs($user);

    Livewire::test(TradingAccountSwitcher::class)
        ->assertSee('Paper')
        ->assertSee('Real ISA')
        ->assertSee('LIVE')
        ->assertSee('DEMO')
        ->call('switchTo', $live->id)
        ->assertRedirect();

    expect(session(ActiveTradingAccount::SESSION_KEY))->toBe($live->id)
        ->and(ActiveTradingAccount::get()->is($live))->toBeTrue();
});

it('shows a red banner while a live account is active', function () {
    FakesTrading212::fake('live');
    $user = User::factory()->create();
    TradingAccount::factory()->for($user)->live()->default()->create(['name' => 'Real ISA']);

    $this->actingAs($user)
        ->get('/')
        ->assertOk()
        ->assertSee('live-account-banner', false)
        ->assertSee('Orders use real money.');
});

it('cannot switch to another user\'s account', function () {
    $user = User::factory()->create();
    $mine = TradingAccount::factory()->for($user)->default()->create();
    $foreign = TradingAccount::factory()->create();

    $this->actingAs($user);

    Livewire::test(TradingAccountSwitcher::class)
        ->assertDontSee($foreign->name)
        ->call('switchTo', $foreign->id)
        ->assertNotFound();

    expect(ActiveTradingAccount::get()->is($mine))->toBeTrue();
});

it('ignores a session selection pointing at another user\'s account', function () {
    $user = User::factory()->create();
    $mine = TradingAccount::factory()->for($user)->default()->create();
    $foreign = TradingAccount::factory()->create();

    $this->actingAs($user);
    session([ActiveTradingAccount::SESSION_KEY => $foreign->id]);

    expect(ActiveTradingAccount::get()->is($mine))->toBeTrue();
});
