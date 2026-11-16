<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\InteractsWithTradingAccount;
use App\Filament\Tables\PositionsTable;
use App\Services\Trading212\Trading212Client;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

/**
 * All open positions of the active account, with shortcuts to buy more or sell.
 */
class Portfolio extends Page implements HasTable
{
    use InteractsWithTable;
    use InteractsWithTradingAccount;

    protected static string|UnitEnum|null $navigationGroup = 'Trading';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static ?int $navigationSort = 1;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return PositionsTable::configure($table)
            ->records(fn (?string $sortColumn, ?string $sortDirection, ?string $search): array => PositionsTable::rows(
                collect($this->fetchFromTrading212(fn (Trading212Client $client): array => $client->positions(), []))
                    ->filter(fn (array $position): bool => blank($search)
                        || str_contains(
                            mb_strtolower(
                                ($position['instrument']['ticker'] ?? '').' '.($position['instrument']['name'] ?? '')
                            ),
                            mb_strtolower($search),
                        ))
                    ->values()
                    ->all(),
                $sortColumn,
                $sortDirection,
            ))
            ->searchable()
            ->emptyStateHeading('No open positions')
            ->emptyStateDescription(fn (): ?string => $this->tradingApiError)
            ->poll('15s');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('placeOrder')
                ->label('Place order')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(Trade::getUrl()),
        ];
    }
}
