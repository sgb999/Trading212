<?php

namespace App\Filament\Resources\TradeOrders\Pages;

use App\Filament\Resources\TradeOrders\TradeOrderResource;
use Filament\Resources\Pages\ListRecords;

class ListTradeOrders extends ListRecords
{
    protected static string $resource = TradeOrderResource::class;
}
