<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OrderType: string implements HasLabel
{
    case Market = 'MARKET';
    case Limit = 'LIMIT';
    case Stop = 'STOP';
    case StopLimit = 'STOP_LIMIT';

    /**
     * The rate-limit / endpoint key used by the Trading 212 client.
     */
    public function endpointKey(): string
    {
        return match ($this) {
            self::Market => 'orders.market',
            self::Limit => 'orders.limit',
            self::Stop => 'orders.stop',
            self::StopLimit => 'orders.stop_limit',
        };
    }

    public function permission(): Permission
    {
        return match ($this) {
            self::Market => Permission::PlaceMarketOrders,
            self::Limit => Permission::PlaceLimitOrders,
            self::Stop, self::StopLimit => Permission::PlaceStopOrders,
        };
    }

    public function requiresLimitPrice(): bool
    {
        return in_array($this, [self::Limit, self::StopLimit], true);
    }

    public function requiresStopPrice(): bool
    {
        return in_array($this, [self::Stop, self::StopLimit], true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Market => 'Market',
            self::Limit => 'Limit',
            self::Stop => 'Stop',
            self::StopLimit => 'Stop limit',
        };
    }
}
