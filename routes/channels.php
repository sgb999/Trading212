<?php

use App\Models\TradingAccount;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('trading-accounts.{tradingAccount}', function (User $user, TradingAccount $tradingAccount): bool {
    return $tradingAccount->user_id === $user->id;
});
