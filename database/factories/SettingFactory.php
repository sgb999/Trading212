<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2),
            'value' => fake()->word(),
        ];
    }

    /**
     * The global AI agent kill switch, stored with the given raw value.
     */
    public function agentTradingHalted(mixed $value = true): static
    {
        return $this->state(fn () => ['key' => Setting::AGENT_TRADING_HALTED, 'value' => $value]);
    }
}
