<?php

use App\Http\Middleware\AuthenticateAiAgent;
use App\Mcp\Servers\LocalTradingServer;
use App\Mcp\Tools\GetPortfolioTool;
use App\Mcp\Tools\ListAccountsTool;
use App\Mcp\Tools\PlaceOrderTool;
use App\Models\AiAgent;
use App\Models\Role;

function mcpToolsListPayload(): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => (object) []];
}

test('requests without a bearer token are rejected with a JSON-RPC error', function () {
    $this->postJson('/mcp/trading', mcpToolsListPayload())
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate')
        ->assertJsonPath('id', 1)
        ->assertJsonPath('error.code', AuthenticateAiAgent::UNAUTHORIZED_CODE);
});

test('requests with an unknown token are rejected', function () {
    AiAgent::factory()->create();

    $this->withToken('t212a_not-a-real-token')
        ->postJson('/mcp/trading', mcpToolsListPayload())
        ->assertUnauthorized()
        ->assertJsonPath('error.code', AuthenticateAiAgent::UNAUTHORIZED_CODE);
});

test('requests from an inactive agent are rejected', function () {
    $agent = AiAgent::factory()->inactive()->create();
    $token = $agent->issueToken();

    $this->withToken($token)
        ->postJson('/mcp/trading', mcpToolsListPayload())
        ->assertUnauthorized();
});

test('an authenticated agent sees the tools its role allows and its last use is recorded', function () {
    $this->freezeSecond();
    $agent = AiAgent::factory()->for(Role::factory()->readOnly())->create(['last_used_at' => null]);
    $token = $agent->issueToken();

    $response = $this->withToken($token)->postJson('/mcp/trading', mcpToolsListPayload());

    $response->assertOk();
    $names = collect($response->json('result.tools'))->pluck('name');
    expect($names)->toContain('list-accounts', 'get-portfolio', 'get-my-permissions')
        ->not->toContain('place-order', 'cancel-order')
        ->and($agent->fresh()->last_used_at->equalTo(now()))->toBeTrue();
});

test('last use is only refreshed once per minute', function () {
    $this->freezeSecond();
    $agent = AiAgent::factory()->create(['last_used_at' => now()->subSeconds(30)]);
    $token = $agent->issueToken();

    $this->withToken($token)->postJson('/mcp/trading', mcpToolsListPayload())->assertOk();

    expect($agent->fresh()->last_used_at->equalTo(now()->subSeconds(30)))->toBeTrue();
});

test('tool calls over HTTP run as the authenticated agent', function () {
    $agent = AiAgent::factory()->for(Role::factory()->readOnly()->state(['name' => 'Analyst']))->create();
    $token = $agent->issueToken();

    $this->withToken($token)
        ->postJson('/mcp/trading', [
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/call',
            'params' => ['name' => 'get-my-permissions', 'arguments' => (object) []],
        ])
        ->assertOk()
        ->assertJsonPath('result.isError', false)
        ->assertJsonPath('result.structuredContent.role', 'Analyst');

    expect($agent->activityLogs()->sole()->tool)->toBe('get-my-permissions');
});

test('the local stdio server authenticates with the configured agent token', function () {
    $agent = AiAgent::factory()->for(Role::factory()->readOnly())->create();
    config(['services.mcp.agent_token' => $agent->issueToken()]);

    LocalTradingServer::tools()
        ->assertRegistered([ListAccountsTool::class, GetPortfolioTool::class])
        ->assertNotRegistered([PlaceOrderTool::class]);
});

test('the local stdio server refuses to start without a valid agent token', function () {
    config(['services.mcp.agent_token' => 'bogus']);

    LocalTradingServer::tools();
})->throws(RuntimeException::class, 'MCP_AGENT_TOKEN');
