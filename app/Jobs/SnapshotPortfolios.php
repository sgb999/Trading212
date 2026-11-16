<?php

namespace App\Jobs;

use App\Actions\RecordPortfolioSnapshot;
use App\Models\TradingAccount;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scheduled (weekdays 08:05 and 16:40 Europe/London) snapshot of every trading account.
 *
 * Each account's summary is fetched fresh (bypassing the response cache) and recorded regardless
 * of the 15-minute throttle. A failure on one account is logged and does not stop the others.
 */
class SnapshotPortfolios implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Not retried: a retry would record a second snapshot for the accounts that already succeeded.
     */
    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function handle(RecordPortfolioSnapshot $recordSnapshot): void
    {
        TradingAccount::query()->lazyById()->each(function (TradingAccount $account) use ($recordSnapshot): void {
            try {
                $snapshot = $recordSnapshot->handle($account, $account->client()->accountSummary(fresh: true), force: true);

                if ($snapshot === null) {
                    Log::warning('Portfolio snapshot skipped: account summary has no total value.', [
                        'trading_account_id' => $account->getKey(),
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('Portfolio snapshot failed.', [
                    'trading_account_id' => $account->getKey(),
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        });
    }
}
