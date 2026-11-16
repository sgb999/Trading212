<?php

use App\Enums\Trading212Environment;
use App\Filament\Resources\TradingAccounts\Pages\CreateTradingAccount;
use App\Filament\Resources\TradingAccounts\Pages\EditTradingAccount;
use App\Filament\Resources\TradingAccounts\Pages\ListTradingAccounts;
use App\Filament\Resources\TradingAccounts\TradingAccountResource;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Filament\FakesTrading212;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('lists only the current user\'s accounts', function () {
    $mine = TradingAccount::factory()->for($this->user)->count(2)->create();
    $foreign = TradingAccount::factory()->create();

    Livewire::test(ListTradingAccounts::class)
        ->assertCanSeeTableRecords($mine)
        ->assertCanNotSeeTableRecords([$foreign]);
});

it('forbids editing another user\'s account', function () {
    $foreign = TradingAccount::factory()->create();

    $this->get(TradingAccountResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();
});

it('creates an account with encrypted credentials for the current user', function () {
    Livewire::test(CreateTradingAccount::class)
        ->fillForm([
            'name' => 'Paper trading',
            'environment' => Trading212Environment::Demo->value,
            'api_key' => 'key-123',
            'api_secret' => 'secret-456',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $account = TradingAccount::sole();

    expect($account->user_id)->toBe($this->user->id)
        ->and($account->api_key)->toBe('key-123')
        ->and($account->api_secret)->toBe('secret-456')
        ->and($account->is_default)->toBeTrue();

    $raw = DB::table('trading_accounts')->where('id', $account->id)->first();
    expect($raw->api_key)->not->toContain('key-123')
        ->and($raw->api_secret)->not->toContain('secret-456');
});

it('requires credentials when creating', function () {
    Livewire::test(CreateTradingAccount::class)
        ->fillForm(['name' => 'No creds', 'environment' => 'demo'])
        ->call('create')
        ->assertHasFormErrors(['api_key' => 'required', 'api_secret' => 'required']);
});

it('never fills the stored credentials back into the edit form', function () {
    $account = TradingAccount::factory()->for($this->user)->create([
        'api_key' => 'stored-key',
        'api_secret' => 'stored-secret',
    ]);

    Livewire::test(EditTradingAccount::class, ['record' => $account->getRouteKey()])
        ->assertSchemaStateSet(['api_key' => null, 'api_secret' => null])
        ->assertDontSee('stored-key')
        ->assertDontSee('stored-secret');
});

it('keeps the existing credentials when the fields are left blank on edit', function () {
    $account = TradingAccount::factory()->for($this->user)->create([
        'api_key' => 'stored-key',
        'api_secret' => 'stored-secret',
    ]);

    Livewire::test(EditTradingAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['name' => 'Renamed', 'api_key' => '', 'api_secret' => ''])
        ->call('save')
        ->assertHasNoFormErrors();

    $account->refresh();

    expect($account->name)->toBe('Renamed')
        ->and($account->api_key)->toBe('stored-key')
        ->and($account->api_secret)->toBe('stored-secret');
});

it('replaces the credentials when new ones are entered', function () {
    $account = TradingAccount::factory()->for($this->user)->create([
        'api_key' => 'old-key',
        'api_secret' => 'old-secret',
    ]);

    Livewire::test(EditTradingAccount::class, ['record' => $account->getRouteKey()])
        ->fillForm(['api_key' => 'new-key', 'api_secret' => 'new-secret'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($account->refresh()->api_key)->toBe('new-key')
        ->and($account->api_secret)->toBe('new-secret');
});

it('keeps only one default account per user', function () {
    $first = TradingAccount::factory()->for($this->user)->default()->create();
    $second = TradingAccount::factory()->for($this->user)->create();

    Livewire::test(EditTradingAccount::class, ['record' => $second->getRouteKey()])
        ->fillForm(['is_default' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($first->refresh()->is_default)->toBeFalse()
        ->and($second->refresh()->is_default)->toBeTrue();
});

it('tests the connection and stores the Trading 212 account id', function () {
    FakesTrading212::fake();
    $account = TradingAccount::factory()->for($this->user)->create(['api_key' => 'k', 'api_secret' => 's']);

    Livewire::test(ListTradingAccounts::class)
        ->callTableAction('testConnection', $account)
        ->assertNotified('Connected to Trading 212');

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://demo.trading212.com/api/v0/equity/account/summary'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('k:s')));

    expect($account->refresh()->external_account_id)->toBe(7654321)
        ->and($account->currency)->toBe('GBP');
});

it('reports rejected credentials when testing the connection', function () {
    Http::fake(['demo.trading212.com/*' => Http::response(['message' => 'Unauthorized'], 401)]);
    $account = TradingAccount::factory()->for($this->user)->create();

    Livewire::test(EditTradingAccount::class, ['record' => $account->getRouteKey()])
        ->callAction('testConnection')
        ->assertNotified('Connection failed');
});

it('tests live accounts against the live API', function () {
    FakesTrading212::fake('live');
    $account = TradingAccount::factory()->for($this->user)->live()->create();

    Livewire::test(EditTradingAccount::class, ['record' => $account->getRouteKey()])
        ->callAction('testConnection')
        ->assertNotified('Connected to Trading 212');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://live.trading212.com/'));
});
