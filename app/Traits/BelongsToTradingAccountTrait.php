<?php

namespace App\Traits;

use App\Models\TradingAccount;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTradingAccountTrait
{
    /**
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
    }
}
