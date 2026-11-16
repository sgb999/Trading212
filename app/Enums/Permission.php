<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * Capabilities that can be granted to an AI agent through its Role.
 */
enum Permission: string implements HasDescription, HasLabel
{
    case ViewAccount = 'account.view';
    case ViewPortfolio = 'portfolio.view';
    case ViewOrders = 'orders.view';
    case ViewHistory = 'history.view';
    case ViewInstruments = 'instruments.view';
    case PlaceMarketOrders = 'orders.place.market';
    case PlaceLimitOrders = 'orders.place.limit';
    case PlaceStopOrders = 'orders.place.stop';
    case Sell = 'orders.sell';
    case CancelOrders = 'orders.cancel';
    case TradeLive = 'environment.live';

    public function getLabel(): string
    {
        return match ($this) {
            self::ViewAccount => 'View account summary',
            self::ViewPortfolio => 'View portfolio / positions',
            self::ViewOrders => 'View pending orders',
            self::ViewHistory => 'View order, dividend & transaction history',
            self::ViewInstruments => 'Search instruments',
            self::PlaceMarketOrders => 'Place market orders',
            self::PlaceLimitOrders => 'Place limit orders',
            self::PlaceStopOrders => 'Place stop & stop-limit orders',
            self::Sell => 'Sell (place orders with negative quantity)',
            self::CancelOrders => 'Cancel pending orders',
            self::TradeLive => 'Act on LIVE (real money) accounts',
        };
    }

    public function getDescription(): ?string
    {
        return match ($this) {
            self::TradeLive => 'Without this, the agent can only touch demo accounts — including read access.',
            self::Sell => 'Without this, the agent may only buy.',
            default => null,
        };
    }

    /**
     * @return list<self>
     */
    public static function readOnly(): array
    {
        return [self::ViewAccount, self::ViewPortfolio, self::ViewOrders, self::ViewHistory, self::ViewInstruments];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $permission) => [$permission->value => $permission->getLabel()])->all();
    }
}
