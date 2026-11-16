<?php

namespace App\Models;

use App\Actions\RecordPortfolioSnapshot;
use Carbon\CarbonInterface;
use Database\Factories\PortfolioSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Point-in-time value of a Trading 212 account, taken from `GET /equity/account/summary`
 * (AccountSummary schema), all amounts in the account's primary currency:
 *
 * - `total_value`: `totalValue`, the whole account (cash + investments).
 * - `cash`: all uninvested cash, `cash.availableToTrade + cash.reservedForOrders + cash.inPies`.
 * - `invested`: market value of the open positions, `investments.currentValue` (not the cost basis `totalCost`).
 * - `currency`: `currency` (ISO 4217), falling back to the account's stored currency.
 * - `external_account_id`: `id`, the Trading 212 account the API key pointed at when the snapshot was taken.
 *
 * The spec describes `totalValue` only as "Investments value in your account's primary currency"; Trading 212
 * reports it as the whole account value, so it is required to match cash + invested within a tolerance
 * (see {@see RecordPortfolioSnapshot}).
 *
 * The first snapshot per Trading 212 account id is the local account's baseline (see {@see TradingAccount::baselineSnapshot()}).
 *
 * @property numeric-string $total_value
 * @property numeric-string $cash
 * @property numeric-string $invested
 * @property CarbonInterface $taken_at
 */
#[Fillable([
    'trading_account_id',
    'external_account_id',
    'total_value',
    'cash',
    'invested',
    'currency',
    'taken_at',
])]
class PortfolioSnapshot extends Model
{
    /** @use HasFactory<PortfolioSnapshotFactory> */
    use HasFactory;

    /**
     * Timezone that defines a trading "day" (Trading 212 Stocks ISA, London Stock Exchange).
     */
    public const string DAY_TIMEZONE = 'Europe/London';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_value' => 'decimal:4',
            'cash' => 'decimal:4',
            'invested' => 'decimal:4',
            'taken_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }

    /**
     * Snapshots taken on the given Europe/London calendar day (today when omitted).
     *
     * @param  Builder<PortfolioSnapshot>  $query
     */
    #[Scope]
    protected function takenOnLondonDay(Builder $query, ?CarbonInterface $day = null): void
    {
        $londonDay = ($day ?? now())->toImmutable()->setTimezone(self::DAY_TIMEZONE);

        $query->whereBetween('taken_at', [
            $londonDay->startOfDay()->setTimezone(config('app.timezone')),
            $londonDay->endOfDay()->setTimezone(config('app.timezone')),
        ]);
    }
}
