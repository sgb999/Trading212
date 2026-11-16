<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTradingAccount;
use App\Filament\Tables\PositionsTable;
use App\Services\Trading212\Trading212Client;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;

/**
 * Headline numbers for the active trading account (refreshes via Echo, polls as a fallback).
 */
class AccountOverview extends StatsOverviewWidget
{
    use InteractsWithTradingAccount;

    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = '15s';

    protected int|array|null $columns = ['@sm' => 2, '@xl' => 5, '!@lg' => 5];

    protected function getHeading(): ?string
    {
        $account = $this->tradingAccount();

        return $account ? "{$account->name} · {$account->environment->getLabel()}" : 'Account';
    }

    protected function getStats(): array
    {
        $summary = $this->fetchFromTrading212(fn (Trading212Client $client): array => $client->accountSummary(), []);

        if ($summary === []) {
            return [
                Stat::make('Account', 'Unavailable')
                    ->description($this->tradingApiError ?? 'No data returned by Trading 212.')
                    ->descriptionIcon(Heroicon::ExclamationTriangle)
                    ->color('warning'),
            ];
        }

        $currency = $summary['currency'] ?? $this->tradingAccount()->currency ?? 'GBP';
        $money = fn (mixed $value): string => Number::currency((float) $value, in: $currency);
        $unrealised = (float) ($summary['investments']['unrealizedProfitLoss'] ?? 0);
        $realised = (float) ($summary['investments']['realizedProfitLoss'] ?? 0);
        $totalCost = (float) ($summary['investments']['totalCost'] ?? 0);

        return [
            Stat::make('Total value', $money($summary['totalValue'] ?? 0))
                ->icon(Heroicon::OutlinedBanknotes),
            Stat::make('Cash available', $money($summary['cash']['availableToTrade'] ?? 0))
                ->description('Reserved for orders: '.$money($summary['cash']['reservedForOrders'] ?? 0))
                ->icon(Heroicon::OutlinedWallet),
            Stat::make('Invested', $money($summary['investments']['currentValue'] ?? 0))
                ->description('Cost basis: '.$money($totalCost))
                ->icon(Heroicon::OutlinedChartPie),
            Stat::make('Unrealised P&L', $money($unrealised))
                ->description($totalCost > 0 ? sprintf('%+.2f%%', $unrealised / $totalCost * 100) : null)
                ->descriptionIcon($unrealised >= 0 ? Heroicon::ArrowTrendingUp : Heroicon::ArrowTrendingDown)
                ->color(PositionsTable::pnlColor($unrealised)),
            Stat::make('Realised P&L', $money($realised))
                ->color(PositionsTable::pnlColor($realised)),
        ];
    }
}
