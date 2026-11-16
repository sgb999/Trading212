<?php

namespace App\Filament\Resources\TradingAccounts\Pages;

use App\Filament\Resources\TradingAccounts\Actions\TestConnectionAction;
use App\Filament\Resources\TradingAccounts\TradingAccountResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTradingAccount extends EditRecord
{
    protected static string $resource = TradingAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TestConnectionAction::make(),
            DeleteAction::make(),
        ];
    }
}
