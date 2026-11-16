<?php

namespace App\Filament\Resources\TradingAccounts\Pages;

use App\Filament\Resources\TradingAccounts\TradingAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTradingAccounts extends ListRecords
{
    protected static string $resource = TradingAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
