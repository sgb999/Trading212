<?php

use App\Events\PortfolioUpdated;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

beforeEach(function () {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    Broadcast::forgetDrivers();
    require base_path('routes/channels.php');
});

test('the account owner may subscribe to the account channel', function () {
    $account = TradingAccount::factory()->create();

    $this->actingAs($account->user)
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-trading-accounts.{$account->id}",
        ])
        ->assertOk()
        ->assertJsonStructure(['auth']);
});

test('another user may not subscribe to the account channel', function () {
    $account = TradingAccount::factory()->create();

    $this->actingAs(User::factory()->create())
        ->post('/broadcasting/auth', [
            'socket_id' => '1234.5678',
            'channel_name' => "private-trading-accounts.{$account->id}",
        ])
        ->assertForbidden();
});

test('guests may not subscribe to the account channel', function () {
    $account = TradingAccount::factory()->create();

    $this->post('/broadcasting/auth', [
        'socket_id' => '1234.5678',
        'channel_name' => "private-trading-accounts.{$account->id}",
    ])
        ->assertForbidden();
});

test('portfolio updates broadcast on the channel the owner is authorised for', function () {
    $account = TradingAccount::factory()->create();
    $event = new PortfolioUpdated($account);

    expect($event->broadcastOn()[0]->name)->toBe("private-trading-accounts.{$account->id}")
        ->and($event->broadcastAs())->toBe('portfolio.updated')
        ->and($event->broadcastWith())->toHaveKey('trading_account_id', $account->id)
        ->and($event->broadcastWith())->not->toHaveKeys(['api_key', 'api_secret']);
});
