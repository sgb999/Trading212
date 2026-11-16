<?php

use App\Actions\CancelOrder;
use App\Enums\Permission;
use App\Enums\TradeOrderStatus;
use App\Events\PortfolioUpdated;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

test('a user cancels an order and the local record is marked cancelled', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/orders/42' => Http::response()]);
    $account = TradingAccount::factory()->create();
    $tradeOrder = TradeOrder::factory()->for($account)->create(['external_id' => 42]);
    $otherOrder = TradeOrder::factory()->for($account)->create(['external_id' => 43]);
    Event::fake([PortfolioUpdated::class]);

    app(CancelOrder::class)->handle($account, 42, $account->user);

    expect($tradeOrder->refresh()->status)->toBe(TradeOrderStatus::Cancelled)
        ->and($tradeOrder->external_status)->toBe('CANCELLING')
        ->and($otherOrder->refresh()->status)->toBe(TradeOrderStatus::Submitted);
    Http::assertSent(fn ($request) => $request->method() === 'DELETE');
    Event::assertDispatched(
        PortfolioUpdated::class,
        fn (PortfolioUpdated $event) => $event->tradingAccount->is($account),
    );
});

test('an agent with the cancel permission may cancel', function () {
    Http::fake(['demo.trading212.com/api/v0/equity/orders/42' => Http::response()]);
    $account = TradingAccount::factory()->create();
    $agent = AiAgent::factory()->for(Role::factory()->withPermissions([Permission::CancelOrders]))->create();
    $agent->tradingAccounts()->attach($account);

    app(CancelOrder::class)->handle($account, 42, $agent);

    Http::assertSentCount(1);
});

test('an agent without the cancel permission or account access is denied', function (
    bool $hasPermission,
    bool $assigned,
) {
    $account = TradingAccount::factory()->create();
    $permissions = $hasPermission ? [Permission::CancelOrders] : Permission::readOnly();
    $agent = AiAgent::factory()->for(Role::factory()->withPermissions($permissions))->create();

    if ($assigned) {
        $agent->tradingAccounts()->attach($account);
    }

    expect(fn () => app(CancelOrder::class)->handle($account, 42, $agent))
        ->toThrow(AgentNotAuthorizedException::class);
    Http::assertNothingSent();
})->with([
    'missing permission' => [false, true],
    'not assigned' => [true, false],
]);
