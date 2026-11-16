<?php

namespace Database\Factories;

use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\TradeOrderStatus;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TradeOrder>
 */
class TradeOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'trading_account_id' => TradingAccount::factory(),
            'external_id' => fake()->unique()->numberBetween(1_000_000, 9_999_999),
            'ticker' => fake()->randomElement(['AAPL_US_EQ', 'MSFT_US_EQ', 'TSLA_US_EQ', 'VUSAl_EQ']),
            'type' => OrderType::Market,
            'side' => OrderSide::Buy,
            'quantity' => fake()->randomFloat(2, 0.1, 10),
            'status' => TradeOrderStatus::Submitted,
            'external_status' => 'NEW',
        ];
    }
}
