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
     * Recorded just before the order request is sent and replaced by the outcome when it returns.
     * A row left in this state (the process died mid-request) is reconciled like {@see self::Unknown}.
     */
    case Sending = 'sending';

    /**
     * The order request got no usable answer (connection error, timeout or 5xx), so Trading 212
     * may or may not have accepted it. Resolved later by the account sync job.
     */
    case Unknown = 'unknown';

    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Filled = 'filled';

    /**
     * Statuses whose outcome on Trading 212 is not yet known: they count toward the daily order
     * limit, block new orders on their ticker and are matched against remote orders by the sync job.
     *
     * @return list<self>
     */
    public static function unresolved(): array
    {
        return [self::Sending, self::Unknown];
    }

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Submitted => 'warning',
            self::Sending, self::Unknown => 'info',
            self::Failed => 'danger',
            self::Cancelled => 'gray',
            self::Filled => 'success',
        };
    }
}
