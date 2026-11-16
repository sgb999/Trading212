<?php

namespace Database\Factories;

use App\Enums\Trading212Environment;
use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TradingAccount>
 */
class TradingAccountFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' account',
            'environment' => Trading212Environment::Demo,
            'api_key' => Str::random(32),
            'api_secret' => Str::random(32),
            'currency' => 'GBP',
            'is_default' => false,
        ];
    }

    public function live(): static
    {
        return $this->state(fn () => ['environment' => Trading212Environment::Live]);
    }

    public function default(): static
    {
        return $this->state(fn () => ['is_default' => true]);
    }
}
