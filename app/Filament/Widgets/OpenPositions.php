<?php

namespace App\Filament\Widgets;

use App\Filament\Concerns\InteractsWithTradingAccount;
use App\Filament\Pages\Portfolio;
use App\Filament\Tables\PositionsTable;
use App\Services\Trading212\Trading212Client;
use Filament\Actions\Action;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * Open positions of the active trading account on the dashboard.
 */
class OpenPositions extends TableWidget
{
    use InteractsWithTradingAccount;

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return PositionsTable::configure($table)
            ->heading('Open positions')
            ->records(fn (?string $sortColumn, ?string $sortDirection): array => PositionsTable::rows(
                $this->fetchFromTrading212(fn (Trading212Client $client): array => $client->positions(), []),
                $sortColumn,
                $sortDirection,
            ))
            ->emptyStateHeading('No open positions')
            ->emptyStateDescription(fn (): ?string => $this->tradingApiError)
            ->headerActions([
                Action::make('portfolio')
                    ->label('Open portfolio')
                    ->link()
                    ->url(Portfolio::getUrl()),
            ])
            ->poll('15s');
    }
}
