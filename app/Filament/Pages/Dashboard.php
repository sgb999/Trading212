<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AccountOverview;
use App\Filament\Widgets\OpenPositions;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Trading';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public function getWidgets(): array
    {
        return [
            AccountOverview::class,
            OpenPositions::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
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
