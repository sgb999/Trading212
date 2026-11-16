<?php

namespace Database\Factories;

use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentActivityLog>
 */
class AgentActivityLogFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ai_agent_id' => AiAgent::factory(),
            'tool' => 'get-portfolio',
            'arguments' => [],
            'outcome' => AgentActivityLog::OUTCOME_ALLOWED,
        ];
    }
}
