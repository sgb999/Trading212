<?php

use App\Actions\CancelOrder;
use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Filament\Pages\Dashboard;
use App\Mcp\CurrentAgent;
use App\Mcp\Servers\TradingServer;
use App\Mcp\Tools\CancelOrderTool;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\Setting;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->account = TradingAccount::factory()->create();
});

function killSwitchAgent(TradingAccount $account): AiAgent
{
    $agent = AiAgent::factory()->for(Role::factory()->trader()->create())->create();
    $agent->tradingAccounts()->attach($account);
    app(CurrentAgent::class)->set($agent);

    return $agent;
}

test('the kill switch is off when no setting is stored', function () {
    expect(Setting::isAgentTradingHalted())->toBeFalse();
});

test('a stored value that is recognisably false means not halted', function (mixed $value) {
    Setting::factory()->agentTradingHalted($value)->create();

    expect(Setting::isAgentTradingHalted())->toBeFalse();
})->with([false, 0, '0', 'false', 'off', 'no']);

test('any other stored value fails closed as halted', function (mixed $value) {
    Setting::factory()->agentTradingHalted($value)->create();

    expect(Setting::isAgentTradingHalted())->toBeTrue();
})->with([
    'true' => [true],
    'one' => [1],
    'string one' => ['1'],
    'string true' => ['true'],
    'garbage' => ['banana'],
    'array' => [['halted' => false]],
    'null' => [null],
]);

test('agent orders are denied, logged and never sent while the kill switch is on', function () {
    Setting::setAgentTradingHalted(true);
    Log::spy();
    $agent = killSwitchAgent($this->account);

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

test('agent cancellations are denied, logged and never sent while the kill switch is on', function () {
    Setting::setAgentTradingHalted(true);
    Log::spy();
    $agent = killSwitchAgent($this->account);

    TradingServer::tool(CancelOrderTool::class, ['order_id' => 777])
        ->assertHasErrors(['Permission denied', 'kill switch']);

    Http::assertNothingSent();
    $log = AgentActivityLog::query()->sole();
    expect($log->ai_agent_id)->toBe($agent->id)
        ->and($log->tool)->toBe('cancel-order')
        ->and($log->outcome)->toBe(AgentActivityLog::OUTCOME_DENIED);
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => str_contains($message, 'kill switch')
            && $context['external_order_id'] === 777)
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

test('human cancellations still go through while the kill switch is on', function () {
    Setting::setAgentTradingHalted(true);
    Http::fake(['demo.trading212.com/api/v0/equity/orders/42' => Http::response()]);

    app(CancelOrder::class)->handle($this->account, 42, $this->account->user);

    Http::assertSentCount(1);
});

test('the dashboard halts and resumes agent trading and audits each change', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    Log::spy();

    Livewire::test(Dashboard::class)
        ->assertSee('AI agent trading is enabled.')
        ->assertActionVisible('haltAgentTrading')
        ->assertActionHidden('resumeAgentTrading')
        ->callAction('haltAgentTrading')
        ->assertNotified('AI agent trading halted')
        ->assertSee('AI agent trading is HALTED')
        ->assertActionHidden('haltAgentTrading')
        ->assertActionVisible('resumeAgentTrading');

    expect(Setting::isAgentTradingHalted())->toBeTrue();

    Livewire::test(Dashboard::class)
        ->callAction('resumeAgentTrading')
        ->assertNotified('AI agent trading resumed');

    expect(Setting::isAgentTradingHalted())->toBeFalse();
    foreach ([true, false] as $halted) {
        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context): bool => $context === [
                'user_id' => $user->id,
                'user_email' => $user->email,
                'agent_trading_halted' => $halted,
            ])
            ->once();
    }
});

test('a stale halt confirmation never resumes trading', function () {
    $this->actingAs(User::factory()->create());
    $page = Livewire::test(Dashboard::class)->mountAction('haltAgentTrading');

    Setting::setAgentTradingHalted(true);
    $page->callMountedAction();

    expect(Setting::isAgentTradingHalted())->toBeTrue();
});

test('a stale resume confirmation never halts trading', function () {
    $this->actingAs(User::factory()->create());
    Setting::setAgentTradingHalted(true);
    $page = Livewire::test(Dashboard::class)->mountAction('resumeAgentTrading');

    Setting::setAgentTradingHalted(false);
    $page->callMountedAction();

    expect(Setting::isAgentTradingHalted())->toBeFalse();
});

test('the dashboard reads the kill switch once per render', function () {
    $this->actingAs(User::factory()->create());
    $settingQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$settingQueries): void {
        if (str_contains($query->sql, '"settings"')) {
            $settingQueries++;
        }
    });

    Livewire::test(Dashboard::class)->assertSee('AI agent trading is enabled.');

    expect($settingQueries)->toBe(1);
});

test('the kill switch state survives a cache flush', function () {
    Setting::setAgentTradingHalted(true);

    Cache::flush();

    expect(Setting::isAgentTradingHalted())->toBeTrue();
});
