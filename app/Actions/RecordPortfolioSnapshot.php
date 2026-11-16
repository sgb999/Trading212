<?php

namespace App\Actions;

use App\Models\PortfolioSnapshot;
use App\Models\TradingAccount;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Stores a {@see PortfolioSnapshot} from a Trading 212 AccountSummary response and makes the first
 * snapshot of each Trading 212 account (summary `id`) the local account's permanent baseline.
 *
 * - Without `$force`, at most one snapshot is recorded per account and Trading 212 account id every
 *   {@see self::THROTTLE_MINUTES} minutes (counting snapshots of any origin). The check runs while the
 *   account row is locked, so concurrent callers cannot both pass it.
 * - When the summary's `id` differs from the baseline snapshot's (the API key now points at another
 *   Trading 212 account), the account's `external_account_id` is updated and the baseline reset, so the
 *   next valid snapshot becomes the new baseline.
 * - Summaries that could corrupt the loss guards fail closed: they record nothing and throw
 *   {@see UnexpectedValueException}. That covers a missing id, a total value of zero or less, missing
 *   `cash.availableToTrade` or `investments.currentValue`, negative or out-of-range amounts, a malformed
 *   currency, and a `totalValue` that differs from cash + invested by more than
 *   {@see self::CONSISTENCY_TOLERANCE_RATIO} of the total or {@see self::CONSISTENCY_TOLERANCE_MINIMUM},
 *   whichever is larger.
 */
class RecordPortfolioSnapshot
{
    public const int THROTTLE_MINUTES = 15;

    public const float CONSISTENCY_TOLERANCE_RATIO = 0.01;

    public const float CONSISTENCY_TOLERANCE_MINIMUM = 1.0;

    /**
     * Amounts must stay below this to fit the decimal(20, 4) columns with headroom.
     */
    private const float MAX_AMOUNT = 1e15;

    /**
     * @param  array<string, mixed>  $summary
     *
     * @throws UnexpectedValueException when the summary is incomplete or implausible
     */
    public function handle(TradingAccount $account, array $summary, bool $force = false): ?PortfolioSnapshot
    {
        $externalAccountId = $this->externalAccountId($summary);

        try {
            $attributes = $this->validatedAttributes($account, $summary);
            $invalidSummary = null;
        } catch (UnexpectedValueException $e) {
            $attributes = null;
            $invalidSummary = $e;
        }

        $snapshot = DB::transaction(function () use ($account, $externalAccountId, $attributes, $force): ?PortfolioSnapshot {
            $lockedAccount = TradingAccount::query()->whereKey($account->getKey())->lockForUpdate()->firstOrFail();

            $this->switchExternalAccount($lockedAccount, $externalAccountId);

            if ($attributes === null || (! $force && $this->recentlySnapshotted($lockedAccount, $externalAccountId))) {
                $this->syncAccount($account, $lockedAccount);

                return null;
            }

            $snapshot = $lockedAccount->portfolioSnapshots()->create([
                ...$attributes,
                'external_account_id' => $externalAccountId,
                'taken_at' => now(),
            ]);

            if ($lockedAccount->baseline_snapshot_id === null) {
                $lockedAccount->forceFill(['baseline_snapshot_id' => $snapshot->getKey()])->save();
            }

            $this->syncAccount($account, $lockedAccount);

            return $snapshot;
        });

        if ($invalidSummary !== null) {
            throw $invalidSummary;
        }

        return $snapshot;
    }

    /**
     * The summary's Trading 212 account id, as an int or a digit string. Fails closed: without an id the
     * snapshot cannot be tied to a Trading 212 account, so it could silently mix values of two accounts
     * into one baseline. Also used by {@see AuthorizeAgentOrder} for the loss guards.
     *
     * @param  array<string, mixed>  $summary
     *
     * @throws UnexpectedValueException when the id is missing or not a positive integer
     */
    public function externalAccountId(array $summary): int
    {
        $id = $summary['id'] ?? null;

        if (is_string($id) && ctype_digit($id)) {
            $id = (int) $id;
        }

        if (! is_int($id) || $id <= 0) {
            throw new UnexpectedValueException('Account summary has no valid account id.');
        }

        return $id;
    }

    private function switchExternalAccount(TradingAccount $account, int $externalAccountId): void
    {
        $baselineExternalId = $account->baseline_snapshot_id === null
            ? null
            : PortfolioSnapshot::query()->whereKey($account->baseline_snapshot_id)->value('external_account_id');

        if ($baselineExternalId !== null && (int) $baselineExternalId !== $externalAccountId) {
            $account->forceFill(['baseline_snapshot_id' => null]);
        }

        if ((int) $account->external_account_id !== $externalAccountId) {
            $account->forceFill(['external_account_id' => $externalAccountId]);
        }

        if ($account->isDirty()) {
            $account->save();
        }
    }

    private function syncAccount(TradingAccount $account, TradingAccount $lockedAccount): void
    {
        $account->forceFill([
            'external_account_id' => $lockedAccount->external_account_id,
            'baseline_snapshot_id' => $lockedAccount->baseline_snapshot_id,
        ])->syncOriginalAttributes(['external_account_id', 'baseline_snapshot_id']);
    }

    private function recentlySnapshotted(TradingAccount $account, int $externalAccountId): bool
    {
        return $account->portfolioSnapshots()
            ->where('external_account_id', $externalAccountId)
            ->where('taken_at', '>', now()->subMinutes(self::THROTTLE_MINUTES))
            ->exists();
    }

    /**
     * Validates a summary's amounts and currency, including the totalValue = cash + invested consistency rule.
     * Also used by {@see AuthorizeAgentOrder} to check the current summary for the loss guards.
     *
     * @param  array<string, mixed>  $summary
     * @return array{total_value: float, cash: float, invested: float, currency: ?string}
     *
     * @throws UnexpectedValueException when the summary is incomplete or implausible
     */
    public function validatedAttributes(TradingAccount $account, array $summary): array
    {
        $cash = is_array($summary['cash'] ?? null) ? $summary['cash'] : [];
        $investments = is_array($summary['investments'] ?? null) ? $summary['investments'] : [];

        $totalValue = $this->amount($summary['totalValue'] ?? null, 'totalValue');
        $cashValue = $this->amount($cash['availableToTrade'] ?? null, 'cash.availableToTrade')
            + $this->amount($cash['reservedForOrders'] ?? 0, 'cash.reservedForOrders')
            + $this->amount($cash['inPies'] ?? 0, 'cash.inPies');
        $invested = $this->amount($investments['currentValue'] ?? null, 'investments.currentValue');

        if ($totalValue <= 0) {
            throw new UnexpectedValueException('Account summary totalValue must be greater than zero.');
        }

        $tolerance = max(self::CONSISTENCY_TOLERANCE_MINIMUM, $totalValue * self::CONSISTENCY_TOLERANCE_RATIO);

        if (abs($totalValue - ($cashValue + $invested)) > $tolerance) {
            throw new UnexpectedValueException('Account summary totalValue does not match cash + investments.');
        }

        return [
            'total_value' => $totalValue,
            'cash' => $cashValue,
            'invested' => $invested,
            'currency' => $this->currency($summary['currency'] ?? $account->currency),
        ];
    }

    private function amount(mixed $value, string $field): float
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new UnexpectedValueException("Account summary {$field} is missing or not numeric.");
        }

        $amount = (float) $value;

        if (! is_finite($amount) || $amount < 0 || $amount >= self::MAX_AMOUNT) {
            throw new UnexpectedValueException("Account summary {$field} is out of range.");
        }

        return $amount;
    }

    private function currency(mixed $currency): ?string
    {
        if ($currency === null) {
            return null;
        }

        if (! is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new UnexpectedValueException('Account summary currency is not an ISO 4217 code.');
        }

        return $currency;
    }
}
