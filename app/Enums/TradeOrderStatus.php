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

    /**
     * The order request got no usable answer (connection error, timeout or 5xx), so Trading 212
     * may or may not have accepted it. Resolved later by the account sync job.
     */
    case Unknown = 'unknown';

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
            self::Unknown => 'info',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
            self::Filled => 'success',
        };
    }
}
