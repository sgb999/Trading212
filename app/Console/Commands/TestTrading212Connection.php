<?php

namespace App\Console\Commands;

use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\Trading212Exception;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('trading212:test-connection {account : Trading account ID}')]
#[Description('Call the Trading 212 API for an account and print its summary')]
class TestTrading212Connection extends Command
{
    public function handle(): int
    {
        $account = ctype_digit((string) $this->argument('account'))
            ? TradingAccount::query()->find((int) $this->argument('account'))
            : null;

        if ($account === null) {
            $this->components->error("Trading account [{$this->argument('account')}] not found.");

            return self::FAILURE;
        }

        try {
            $summary = $account->client()->accountSummary(fresh: true);
        } catch (Trading212Exception $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Connected to {$account->name} ({$account->environment->getLabel()}).");

        $this->table(['Field', 'Value'], [
            ['Account ID', $summary['id'] ?? '-'],
            ['Currency', $summary['currency'] ?? '-'],
            ['Total value', $summary['totalValue'] ?? '-'],
            ['Available to trade', $summary['cash']['availableToTrade'] ?? '-'],
            ['Reserved for orders', $summary['cash']['reservedForOrders'] ?? '-'],
            ['Cash in pies', $summary['cash']['inPies'] ?? '-'],
            ['Investments value', $summary['investments']['currentValue'] ?? '-'],
            ['Unrealized P/L', $summary['investments']['unrealizedProfitLoss'] ?? '-'],
        ]);

        return self::SUCCESS;
    }
}
