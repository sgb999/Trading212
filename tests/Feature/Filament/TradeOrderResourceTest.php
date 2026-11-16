<?php

use App\Enums\TradeOrderStatus;
use App\Filament\Resources\TradeOrders\Pages\ListTradeOrders;
use App\Filament\Resources\TradeOrders\Pages\ViewTradeOrder;
use App\Filament\Resources\TradeOrders\TradeOrderResource;
use App\Models\AiAgent;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->account = TradingAccount::factory()->for($this->user)->create(['name' => 'Paper']);
    $this->actingAs($this->user);
});

it('only lists orders of the user\'s own accounts', function () {
    $mine = TradeOrder::factory()->for($this->account)->for($this->user, 'placedBy')->create();
    $foreign = TradeOrder::factory()->create();

    Livewire::test(ListTradeOrders::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);

    $this->get(TradeOrderResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
});

it('shows who placed each order, including AI agents and errors', function () {
    $agent = AiAgent::factory()->for($this->user)->create(['name' => 'Momentum bot']);
    TradeOrder::factory()->for($this->account)->for($agent, 'placedBy')->create([
        'status' => TradeOrderStatus::Failed,
        'error' => 'Insufficient funds',
    ]);

    Livewire::test(ListTradeOrders::class)
        ->assertSee('Agent: Momentum bot')
        ->assertSee('Insufficient funds');
});

it('filters by account, status and placer', function () {
    $agent = AiAgent::factory()->for($this->user)->create();
    $other = TradingAccount::factory()->for($this->user)->create();

    $byUser = TradeOrder::factory()->for($this->account)->for($this->user, 'placedBy')->create();
    $byAgent = TradeOrder::factory()
        ->for($this->account)
        ->for($agent, 'placedBy')
        ->create(['status' => TradeOrderStatus::Failed]);
    $onOther = TradeOrder::factory()->for($other)->for($this->user, 'placedBy')->create();

    Livewire::test(ListTradeOrders::class)
        ->filterTable('trading_account_id', $other->id)
        ->assertCanSeeTableRecords([$onOther])
        ->assertCanNotSeeTableRecords([$byUser, $byAgent])
        ->resetTableFilters()
        ->filterTable('status', TradeOrderStatus::Failed->value)
        ->assertCanSeeTableRecords([$byAgent])
        ->assertCanNotSeeTableRecords([$byUser, $onOther])
        ->resetTableFilters()
        ->filterTable('placed_by', "agent:{$agent->id}")
        ->assertCanSeeTableRecords([$byAgent])
        ->assertCanNotSeeTableRecords([$byUser, $onOther])
        ->resetTableFilters()
        ->filterTable('placed_by', 'user')
        ->assertCanSeeTableRecords([$byUser, $onOther])
        ->assertCanNotSeeTableRecords([$byAgent]);
});

it('is read only', function () {
    $order = TradeOrder::factory()->for($this->account)->create();

    expect(TradeOrderResource::canCreate())->toBeFalse()
        ->and(TradeOrderResource::canEdit($order))->toBeFalse()
        ->and(TradeOrderResource::canDelete($order))->toBeFalse()
        ->and(TradeOrderResource::hasPage('create'))->toBeFalse()
        ->and(TradeOrderResource::hasPage('edit'))->toBeFalse();

    Livewire::test(ViewTradeOrder::class, ['record' => $order->getRouteKey()])
        ->assertOk()
        ->assertSee($order->ticker);
});
