<?php

use App\Enums\Permission;
use App\Filament\Resources\AgentActivityLogs\Pages\ListAgentActivityLogs;
use App\Filament\Resources\AiAgents\AiAgentResource;
use App\Filament\Resources\AiAgents\Pages\CreateAiAgent;
use App\Filament\Resources\AiAgents\Pages\EditAiAgent;
use App\Filament\Resources\AiAgents\Pages\ListAiAgents;
use App\Filament\Resources\AiAgents\Pages\ViewAiAgent;
use App\Filament\Resources\AiAgents\RelationManagers\ActivityLogsRelationManager;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradingAccount;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->role = Role::factory()->trader()->create(['name' => 'Trader']);
    $this->actingAs($this->user);
});

it('only lists the user\'s own agents', function () {
    $mine = AiAgent::factory()->for($this->user)->create();
    $foreign = AiAgent::factory()->create();

    Livewire::test(ListAiAgents::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign]);

    $this->get(AiAgentResource::getUrl('view', ['record' => $foreign]))->assertNotFound();
    $this->get(AiAgentResource::getUrl('edit', ['record' => $foreign]))->assertNotFound();
});

it('creates an agent, stores only the token hash and shows the token once', function () {
    $demo = TradingAccount::factory()->for($this->user)->create();

    Livewire::test(CreateAiAgent::class)
        ->fillForm([
            'name' => 'Rebalancer',
            'description' => 'Keeps the portfolio balanced',
            'role_id' => $this->role->id,
            'tradingAccounts' => [$demo->id],
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $agent = AiAgent::sole();
    $flashed = session(ViewAiAgent::TOKEN_SESSION_KEY);
    $token = $flashed['token'];

    expect($agent->user_id)->toBe($this->user->id)
        ->and($agent->tradingAccounts->pluck('id')->all())->toBe([$demo->id])
        ->and($token)->toStartWith(AiAgent::TOKEN_PREFIX)
        ->and($agent->token_hash)->toBe(hash('sha256', $token))
        ->and($agent->getAttributes())->not->toContain($token)
        ->and(AiAgent::findByToken($token)?->is($agent))->toBeTrue();

    $view = Livewire::test(ViewAiAgent::class, ['record' => $agent->getRouteKey()])
        ->assertSet('pendingToken', $token)
        ->call('mountDefaultActions')
        ->assertActionMounted('showToken')
        ->assertMountedActionModalSee($token)
        ->assertMountedActionModalSee('/mcp/trading')
        ->assertMountedActionModalSee('Authorization: Bearer '.$token)
        ->assertSet('pendingToken', null);

    // A second visit never reveals it again.
    Livewire::test(ViewAiAgent::class, ['record' => $agent->getRouteKey()])
        ->assertSet('pendingToken', null)
        ->assertDontSee($token)
        ->assertSee($agent->token_prefix);
});

it('regenerates the token and invalidates the old one', function () {
    $agent = AiAgent::factory()->for($this->user)->for($this->role)->create();
    $oldHash = $agent->token_hash;

    $component = Livewire::test(ViewAiAgent::class, ['record' => $agent->getRouteKey()])
        ->callAction('regenerateToken')
        ->assertActionMounted('showToken');

    $agent->refresh();
    $token = $component->get('mountedActions')[0]['arguments']['token'];

    expect($agent->token_hash)->not->toBe($oldHash)
        ->and($agent->token_hash)->toBe(hash('sha256', $token));

    $component->assertMountedActionModalSee($token);
});

it('regenerates the token from the agents table', function () {
    $agent = AiAgent::factory()->for($this->user)->for($this->role)->create();
    $oldHash = $agent->token_hash;

    Livewire::test(ListAiAgents::class)
        ->callTableAction('regenerateToken', $agent)
        ->assertActionMounted('showToken');

    expect($agent->refresh()->token_hash)->not->toBe($oldHash);
});

it('only offers the user\'s own trading accounts', function () {
    $foreign = TradingAccount::factory()->create();

    Livewire::test(CreateAiAgent::class)
        ->fillForm([
            'name' => 'Sneaky',
            'role_id' => $this->role->id,
            'tradingAccounts' => [$foreign->id],
        ])
        ->call('create')
        ->assertHasFormErrors(['tradingAccounts'])
        ->assertSee('You can only assign your own trading accounts.');

    expect(AiAgent::count())->toBe(0);
});

it('warns when a live account is assigned to an agent', function () {
    $live = TradingAccount::factory()->for($this->user)->live()->create();
    $liveRole = Role::factory()->fullAccess()->create();

    Livewire::test(CreateAiAgent::class)
        ->fillForm(['role_id' => $this->role->id, 'tradingAccounts' => [$live->id]])
        ->assertSee('the role lacks the "live" permission')
        ->fillForm(['role_id' => $liveRole->id])
        ->assertSee('can trade REAL MONEY');
});

it('edits an agent and keeps its token', function () {
    $agent = AiAgent::factory()->for($this->user)->for($this->role)->create();
    $hash = $agent->token_hash;
    $readOnly = Role::factory()->readOnly()->create();

    Livewire::test(EditAiAgent::class, ['record' => $agent->getRouteKey()])
        ->fillForm(['role_id' => $readOnly->id, 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    $agent->refresh();

    expect($agent->role_id)->toBe($readOnly->id)
        ->and($agent->is_active)->toBeFalse()
        ->and($agent->token_hash)->toBe($hash)
        ->and($agent->hasPermission(Permission::ViewAccount))->toBeFalse();
});

it('lists the agent\'s activity logs', function () {
    $agent = AiAgent::factory()->for($this->user)->create();
    $logs = AgentActivityLog::factory()->for($agent)->count(2)->create();
    $denied = AgentActivityLog::factory()->for($agent)->create([
        'outcome' => AgentActivityLog::OUTCOME_DENIED,
        'tool' => 'place-order',
        'message' => 'Role may not sell',
    ]);
    $otherAgentLog = AgentActivityLog::factory()->create();

    Livewire::test(ActivityLogsRelationManager::class, ['ownerRecord' => $agent, 'pageClass' => ViewAiAgent::class])
        ->assertCanSeeTableRecords([...$logs, $denied])
        ->assertCanNotSeeTableRecords([$otherAgentLog])
        ->assertSee('Role may not sell')
        ->filterTable('outcome', AgentActivityLog::OUTCOME_DENIED)
        ->assertCanSeeTableRecords([$denied])
        ->assertCanNotSeeTableRecords($logs);
});

it('lists activity across the user\'s agents only', function () {
    $agent = AiAgent::factory()->for($this->user)->create();
    $mine = AgentActivityLog::factory()->for($agent)->create();
    $foreign = AgentActivityLog::factory()->create();

    Livewire::test(ListAgentActivityLogs::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$foreign])
        ->callTableAction('view', $mine)
        ->assertHasNoErrors();
});
