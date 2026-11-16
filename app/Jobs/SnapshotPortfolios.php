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
 * of the 15-minute throttle. A failure or invalid summary on one account is logged and does not stop the others.
 */
class SnapshotPortfolios implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Not retried: a retry would record a second snapshot for the accounts that already succeeded.
     */
    public int $tries = 1;

    /**
     * Below the queue's `retry_after` (90s) so a slow run is never handed to a second worker.
     */
    public int $timeout = 60;

    public int $uniqueFor = 600;

    public function handle(RecordPortfolioSnapshot $recordSnapshot): void
    {
        TradingAccount::query()->lazyById()->each(function (TradingAccount $account) use ($recordSnapshot): void {
            try {
                $recordSnapshot->handle($account, $account->client()->accountSummary(fresh: true), force: true);
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
