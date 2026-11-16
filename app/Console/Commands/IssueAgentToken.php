<?php

namespace App\Console\Commands;

use App\Models\AiAgent;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading212:agent-token {agent : AI agent ID}')]
#[Description('Issue a new MCP bearer token for an AI agent (revokes the previous one)')]
class IssueAgentToken extends Command
{
    public function handle(): int
    {
        $agent = ctype_digit((string) $this->argument('agent'))
            ? AiAgent::query()->find((int) $this->argument('agent'))
            : null;

        if ($agent === null) {
            $this->components->error("AI agent [{$this->argument('agent')}] not found.");

            return self::FAILURE;
        }

        $token = $agent->issueToken();

        $this->components->info(
            "New token for [{$agent->name}]. It is shown only once; the previous token no longer works."
        );
        $this->line($token);

        if (! $agent->is_active) {
            $this->components->warn('This agent is inactive, so the token will be rejected until it is activated.');
        }

        return self::SUCCESS;
    }
}
