<?php

namespace Database\Factories;

use App\Models\AiAgent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiAgent>
 */
class AiAgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = AiAgent::TOKEN_PREFIX.Str::random(48);

        return [
            'user_id' => User::factory(),
            'role_id' => Role::factory(),
            'name' => fake()->firstName().' bot',
            'description' => fake()->sentence(),
            'token_hash' => hash('sha256', $token),
            'token_prefix' => substr($token, 0, 12),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
