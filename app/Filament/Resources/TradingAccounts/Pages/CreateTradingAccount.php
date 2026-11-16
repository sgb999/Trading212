<?php

namespace App\Filament\Resources\TradingAccounts\Pages;

use App\Filament\Resources\TradingAccounts\TradingAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTradingAccount extends CreateRecord
{
    protected static string $resource = TradingAccountResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        if (! auth()->user()->tradingAccounts()->exists()) {
            $data['is_default'] = true;
        }

        return $data;
    }
}
