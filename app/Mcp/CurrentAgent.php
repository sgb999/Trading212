<?php

namespace App\Mcp;

use App\Models\AiAgent;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Container\Attributes\Scoped;

/**
 * Holds the AI agent authenticated for the current MCP request (or stdio session).
 */
#[Scoped]
class CurrentAgent
{
    private ?AiAgent $agent = null;

    public function set(AiAgent $agent): void
    {
        $this->agent = $agent;
    }

    public function get(): ?AiAgent
    {
        return $this->agent;
    }

    public function check(): bool
    {
        return $this->agent !== null;
    }

    /**
     * @throws AuthenticationException
     */
    public function agent(): AiAgent
    {
        return $this->agent ?? throw new AuthenticationException('No AI agent is authenticated for this MCP session.');
    }

    public function forget(): void
    {
        $this->agent = null;
    }
}
