<?php

namespace Database\Factories;

use App\Models\PortfolioSnapshot;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PortfolioSnapshot>
 */
class PortfolioSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $cash = fake()->randomFloat(2, 0, 100);
        $invested = fake()->randomFloat(2, 0, 150);

        return [
            'trading_account_id' => TradingAccount::factory(),
            'total_value' => $cash + $invested,
            'cash' => $cash,
            'invested' => $invested,
            'currency' => 'GBP',
            'taken_at' => now(),
        ];
    }
}
