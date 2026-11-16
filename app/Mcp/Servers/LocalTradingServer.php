<?php

namespace App\Mcp\Servers;

use App\Mcp\CurrentAgent;
use App\Models\AiAgent;
use RuntimeException;

/**
 * The Trading server over stdio (`php artisan mcp:start trading`), authenticated as the agent
 * whose token is in the MCP_AGENT_TOKEN environment variable.
 */
class LocalTradingServer extends TradingServer
{
    protected function boot(): void
    {
        $currentAgent = app(CurrentAgent::class);

        if ($currentAgent->check()) {
            return;
        }

        $token = config('services.mcp.agent_token');

        $agent = is_string($token) && $token !== '' ? AiAgent::findByToken($token) : null;

        if ($agent === null) {
            throw new RuntimeException(
                'Set MCP_AGENT_TOKEN to the token of an active AI agent to start the local trading MCP server.'
            );
        }

        AiAgent::withoutTimestamps(fn () => $agent->forceFill(['last_used_at' => now()])->saveQuietly());

        $currentAgent->set($agent);
    }
}
