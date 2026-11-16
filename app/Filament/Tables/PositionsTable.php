<?php

namespace App\Filament\Tables;

use App\Filament\Pages\Trade;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Shared configuration for tables listing open Trading 212 positions (array records).
 */
final class PositionsTable
{
    /**
     * Flatten API positions into table rows keyed by ticker.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return array<string, array{
     *     ticker: string,
     *     name: string,
     *     instrument_currency: ?string,
     *     quantity: float,
     *     quantity_available: float,
     *     average_price: float,
     *     current_price: float,
     *     value: float,
     *     cost: float,
     *     pnl: float,
     *     pnl_percent: ?float,
     *     currency: ?string,
     * }>
     */
    public static function rows(array $positions, ?string $sortColumn = null, ?string $sortDirection = null): array
    {
        $rows = collect($positions)
            ->map(function (array $position): array {
                $wallet = $position['walletImpact'] ?? [];
                $cost = (float) ($wallet['totalCost']
                    ?? ((float) ($position['averagePricePaid'] ?? 0) * (float) ($position['quantity'] ?? 0)));
                $pnl = (float) ($wallet['unrealizedProfitLoss'] ?? 0);

                return [
                    'ticker' => (string) ($position['instrument']['ticker'] ?? $position['ticker'] ?? ''),
                    'name' => (string) ($position['instrument']['name'] ?? ''),
                    'instrument_currency' => $position['instrument']['currency'] ?? null,
                    'quantity' => (float) ($position['quantity'] ?? 0),
                    'quantity_available' => (float) (
                        $position['quantityAvailableForTrading'] ?? $position['quantity'] ?? 0
                    ),
                    'average_price' => (float) ($position['averagePricePaid'] ?? 0),
                    'current_price' => (float) ($position['currentPrice'] ?? 0),
                    'value' => (float) ($wallet['currentValue'] ?? 0),
                    'cost' => $cost,
                    'pnl' => $pnl,
                    'pnl_percent' => $cost > 0 ? round($pnl / $cost * 100, 2) : null,
                    'currency' => $wallet['currency'] ?? null,
                ];
            })
            ->filter(fn (array $row): bool => $row['ticker'] !== '');

        $rows = filled($sortColumn)
            ? $rows->sortBy($sortColumn, descending: $sortDirection === 'desc')
            : $rows->sortByDesc('value');

        return $rows->keyBy('ticker')->all();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns(self::columns())
            ->recordActions(self::recordActions())
            ->emptyStateIcon(Heroicon::OutlinedBriefcase)
            ->paginated(false);
    }

    /**
     * @return array<TextColumn>
     */
    public static function columns(): array
    {
        return [
            TextColumn::make('ticker')
                ->label('Instrument')
                ->weight('medium')
                ->description(fn (array $record): string => $record['name'])
                ->sortable(),
            TextColumn::make('quantity')
                ->label('Qty')
                ->numeric(decimalPlaces: 4)
                ->alignEnd()
                ->sortable(),
            TextColumn::make('average_price')
                ->label('Avg price')
                ->money(fn (array $record): ?string => $record['instrument_currency'])
                ->alignEnd(),
            TextColumn::make('current_price')
                ->label('Current price')
                ->money(fn (array $record): ?string => $record['instrument_currency'])
                ->alignEnd(),
            TextColumn::make('value')
                ->label('Value')
                ->money(fn (array $record): ?string => $record['currency'])
                ->alignEnd()
                ->sortable(),
            TextColumn::make('pnl')
                ->label('P&L')
                ->money(fn (array $record): ?string => $record['currency'])
                ->color(fn (float $state): string => self::pnlColor($state))
                ->alignEnd()
                ->sortable(),
            TextColumn::make('pnl_percent')
                ->label('P&L %')
                ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : sprintf('%+.2f%%', $state))
                ->color(fn (?float $state): string => self::pnlColor((float) $state))
                ->badge()
                ->alignEnd()
                ->sortable(),
        ];
    }

    /**
     * @return array<Action>
     */
    public static function recordActions(): array
    {
        return [
            Action::make('buyMore')
                ->label('Buy more')
                ->icon(Heroicon::OutlinedPlusCircle)
                ->color('success')
                ->url(fn (array $record): string => Trade::getUrl(['ticker' => $record['ticker'], 'side' => 'BUY'])),
            Action::make('sell')
                ->label('Sell')
                ->icon(Heroicon::OutlinedMinusCircle)
                ->color('danger')
                ->url(fn (array $record): string => Trade::getUrl([
                    'ticker' => $record['ticker'],
                    'side' => 'SELL',
                    'quantity' => $record['quantity_available'],
                ])),
        ];
    }

    public static function pnlColor(float $value): string
    {
        return match (true) {
            $value > 0 => 'success',
            $value < 0 => 'danger',
            default => 'gray',
        };
    }
}
