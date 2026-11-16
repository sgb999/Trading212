<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Local lifecycle status of an order submitted through this platform.
 * The raw Trading 212 status is stored separately in `external_status`.
 */
enum TradeOrderStatus: string implements HasColor, HasLabel
{
    case Submitted = 'submitted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Filled = 'filled';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'warning',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
            self::Filled => 'success',
        };
    }
}
