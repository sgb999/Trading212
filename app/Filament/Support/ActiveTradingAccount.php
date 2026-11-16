<?php

namespace App\Filament\Support;

use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The trading account the signed-in user is currently working on, stored in the session.
 * Falls back to the user's default account when nothing (or something invalid) is selected.
 */
final class ActiveTradingAccount
{
    public const string SESSION_KEY = 'active_trading_account_id';

    public static function get(): ?TradingAccount
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return null;
        }

        $selectedId = session(self::SESSION_KEY);

        if (filled($selectedId)) {
            $account = $user->tradingAccounts()->find($selectedId);

            if ($account instanceof TradingAccount) {
                return $account;
            }
        }

        return $user->defaultTradingAccount();
    }

    /**
     * @throws AuthorizationException
     */
    public static function set(TradingAccount $account): void
    {
        if ($account->user_id !== auth()->id()) {
            throw new AuthorizationException('You do not own this trading account.');
        }

        session([self::SESSION_KEY => $account->getKey()]);
    }

    public static function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
