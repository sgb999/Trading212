<?php

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Filament\Pages\Dashboard;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = TradingAccount::factory()->create();
});

test('the kill switch is off by default', function () {
    expect(Setting::isAgentTradingHalted())->toBeFalse();
});

test('agent orders are denied, logged and never sent while the kill switch is on', function () {
    Setting::setAgentTradingHalted(true);
    Log::spy();
    $agent = AiAgent::factory()->for(Role::factory()->trader()->create())->create();
    $agent->tradingAccounts()->attach($this->account);
    app(CurrentAgent::class)->set($agent);

    TradingServer::tool(PlaceOrderTool::class, [
        'ticker' => 'AAPL_US_EQ',
        'type' => 'MARKET',
        'side' => 'BUY',
        'quantity' => 1,
    ])->assertHasErrors(['Permission denied', 'kill switch']);

    Http::assertNothingSent();
    expect(TradeOrder::query()->count())->toBe(0);
    $log = AgentActivityLog::query()->sole();
    expect($log->ai_agent_id)->toBe($agent->id)
        ->and($log->tool)->toBe('place-order')
        ->and($log->outcome)->toBe(AgentActivityLog::OUTCOME_DENIED)
        ->and($log->trading_account_id)->toBe($this->account->id);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'kill switch')
            && $context['ai_agent_id'] === $agent->id)
        ->once();
});

test('human orders still go through while the kill switch is on', function () {
    Setting::setAgentTradingHalted(true);
    Http::fake(['demo.trading212.com/api/v0/equity/orders/market' => Http::response(['id' => 777, 'status' => 'NEW'])]);

    $tradeOrder = app(PlaceOrder::class)->handle(
        $this->account,
        new OrderRequest(ticker: 'AAPL_US_EQ', type: OrderType::Market, side: OrderSide::Buy, quantity: 1),
        $this->account->user,
    );

    expect($tradeOrder->external_id)->toBe(777);
    Http::assertSentCount(1);
});

test('the dashboard toggle halts and resumes agent trading', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Dashboard::class)
        ->assertSee('AI agent trading is enabled.')
        ->assertActionHasLabel('toggleAgentTrading', 'Halt AI trading')
        ->callAction('toggleAgentTrading')
        ->assertNotified('AI agent trading halted')
        ->assertSee('AI agent trading is HALTED')
        ->assertActionHasLabel('toggleAgentTrading', 'Resume AI trading');

    expect(Setting::isAgentTradingHalted())->toBeTrue();
    $this->assertDatabaseHas(Setting::class, ['key' => Setting::AGENT_TRADING_HALTED]);

    Livewire::test(Dashboard::class)
        ->callAction('toggleAgentTrading')
        ->assertNotified('AI agent trading resumed');

    expect(Setting::isAgentTradingHalted())->toBeFalse();
});

test('the kill switch state survives a cache flush', function () {
    Setting::setAgentTradingHalted(true);

    Cache::flush();

    expect(Setting::isAgentTradingHalted())->toBeTrue();
});
