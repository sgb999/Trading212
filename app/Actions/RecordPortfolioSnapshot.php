<?php

namespace App\Actions;

use App\Models\PortfolioSnapshot;
use App\Models\TradingAccount;
use Illuminate\Support\Facades\DB;

/**
 * Stores a {@see PortfolioSnapshot} from a Trading 212 AccountSummary response and makes the
 * account's first snapshot its permanent baseline.
 *
 * Without `$force`, at most one snapshot is recorded per account every {@see self::THROTTLE_MINUTES}
 * minutes (counting snapshots of any origin). A summary without a numeric `totalValue` records nothing,
 * so a malformed response can never become a baseline.
 */
class RecordPortfolioSnapshot
{
    public const int THROTTLE_MINUTES = 15;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function handle(TradingAccount $account, array $summary, bool $force = false): ?PortfolioSnapshot
    {
        if (! is_numeric($summary['totalValue'] ?? null)) {
            return null;
        }

        if (! $force && $this->recentlySnapshotted($account)) {
            return null;
        }

        return DB::transaction(function () use ($account, $summary): PortfolioSnapshot {
            $snapshot = $account->portfolioSnapshots()->create([
                'total_value' => $summary['totalValue'],
                'cash' => $this->cash($summary),
                'invested' => $this->amount($summary['investments']['currentValue'] ?? null),
                'currency' => is_string($summary['currency'] ?? null) ? $summary['currency'] : $account->currency,
                'taken_at' => now(),
            ]);

            $isBaseline = TradingAccount::query()
                ->whereKey($account->getKey())
                ->whereNull('baseline_snapshot_id')
                ->update(['baseline_snapshot_id' => $snapshot->getKey()]) === 1;

            if ($isBaseline) {
                $account->forceFill(['baseline_snapshot_id' => $snapshot->getKey()])->syncOriginalAttribute('baseline_snapshot_id');
            }

            return $snapshot;
        });
    }

    private function recentlySnapshotted(TradingAccount $account): bool
    {
        return $account->portfolioSnapshots()
            ->where('taken_at', '>', now()->subMinutes(self::THROTTLE_MINUTES))
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function cash(array $summary): float
    {
        $cash = is_array($summary['cash'] ?? null) ? $summary['cash'] : [];

        return $this->amount($cash['availableToTrade'] ?? null)
            + $this->amount($cash['reservedForOrders'] ?? null)
            + $this->amount($cash['inPies'] ?? null);
    }

    private function amount(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
