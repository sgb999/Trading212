<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\History;
use App\Filament\Pages\PendingOrders;
use App\Filament\Pages\Portfolio;
use App\Filament\Pages\Trade;
use App\Filament\Resources\AgentActivityLogs\AgentActivityLogResource;
use App\Filament\Resources\AiAgents\AiAgentResource;
use App\Filament\Resources\Roles\RoleResource;
use App\Filament\Resources\TradeOrders\TradeOrderResource;
use App\Filament\Resources\TradingAccounts\TradingAccountResource;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Support\Sleep;
use Tests\Feature\Filament\FakesTrading212;

beforeEach(function () {
    Sleep::fake();
    FakesTrading212::fake();
    $this->user = User::factory()->create();
    $this->account = TradingAccount::factory()->for($this->user)->default()->create(['name' => 'Paper']);
    $this->actingAs($this->user);
});

it('renders every page for a signed-in user', function (Closure $url) {
    $this->get($url->call($this))->assertOk()->assertSee('Paper');
})->with([
    'dashboard' => [fn () => Dashboard::getUrl()],
    'portfolio' => [fn () => Portfolio::getUrl()],
    'trade' => [fn () => Trade::getUrl(['ticker' => 'AAPL_US_EQ'])],
    'pending orders' => [fn () => PendingOrders::getUrl()],
    'history' => [fn () => History::getUrl()],
    'order log' => [fn () => TradeOrderResource::getUrl()],
    'order log entry' => [fn () => TradeOrderResource::getUrl('view', [
        'record' => TradeOrder::factory()->for($this->account)->create(),
    ])],
    'accounts' => [fn () => TradingAccountResource::getUrl()],
    'account create' => [fn () => TradingAccountResource::getUrl('create')],
    'account edit' => [fn () => TradingAccountResource::getUrl('edit', ['record' => $this->account])],
    'roles' => [fn () => RoleResource::getUrl()],
    'role create' => [fn () => RoleResource::getUrl('create')],
    'role edit' => [fn () => RoleResource::getUrl('edit', ['record' => Role::factory()->create()])],
    'agents' => [fn () => AiAgentResource::getUrl()],
    'agent create' => [fn () => AiAgentResource::getUrl('create')],
    'agent view' => [fn () => AiAgentResource::getUrl('view', [
        'record' => AgentActivityLog::factory()->for(AiAgent::factory()->for($this->user))->create()->ai_agent_id,
    ])],
    'agent edit' => [fn () => AiAgentResource::getUrl('edit', [
        'record' => AiAgent::factory()->for($this->user)->create(),
    ])],
    'activity' => [fn () => AgentActivityLogResource::getUrl()],
]);

it('requires authentication for every page', function () {
    $this->post('/logout');
    auth()->logout();

    $paths = [
        '/',
        '/portfolio',
        '/trade',
        '/pending-orders',
        '/history',
        '/trade-orders',
        '/trading-accounts',
        '/roles',
        '/ai-agents',
        '/agent-activity',
    ];

    foreach ($paths as $path) {
        $this->get($path)->assertRedirect('/login');
    }
});
