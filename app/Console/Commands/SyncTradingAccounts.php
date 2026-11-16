<?php

namespace App\Console\Commands;

use App\Jobs\SyncTradingAccount;
use App\Models\TradingAccount;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature(
    'trading212:sync'
    .' {account? : Trading account ID (all accounts when omitted)}'
    .' {--now : Run the sync immediately instead of queueing it}'
)]
#[Description('Sync Trading 212 account summary, positions and pending orders')]
class SyncTradingAccounts extends Command
{
    public function handle(): int
    {
        $accountId = $this->argument('account');

        if ($accountId !== null && ! ctype_digit((string) $accountId)) {
            $this->components->error('The account argument must be a trading account ID.');

            return self::FAILURE;
        }

        $accounts = TradingAccount::query()
            ->when($accountId !== null, fn ($query) => $query->whereKey((int) $accountId))
            ->get();

        if ($accountId !== null && $accounts->isEmpty()) {
            $this->components->error("Trading account [{$accountId}] not found.");

            return self::FAILURE;
        }

        foreach ($accounts as $account) {
            $this->option('now')
                ? SyncTradingAccount::dispatchSync($account)
                : SyncTradingAccount::dispatch($account);
        }

        $this->components->info(sprintf(
            '%s sync for %d trading account(s).',
            $this->option('now') ? 'Ran' : 'Queued',
            $accounts->count(),
        ));

        return self::SUCCESS;
    }
}
