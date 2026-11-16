<?php

namespace App\Actions;

use App\Enums\Permission;
use App\Enums\TradeOrderStatus;
use App\Events\PortfolioUpdated;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Trading212\Exceptions\Trading212Exception;

/**
 * Cancels a pending Trading 212 order and marks any matching local TradeOrder as cancelled.
 */
class CancelOrder
{
    /**
     * @throws AgentNotAuthorizedException
     * @throws Trading212Exception
     */
    public function handle(TradingAccount $account, int $externalOrderId, User|AiAgent $actor): void
    {
        if (
            $actor instanceof AiAgent
            && (! $actor->canAccessAccount($account) || ! $actor->hasPermission(Permission::CancelOrders))
        ) {
            throw new AgentNotAuthorizedException('Agent is not allowed to cancel orders on this account.');
        }

        $account->client()->cancelOrder($externalOrderId);

        $account->tradeOrders()
            ->where('external_id', $externalOrderId)
            ->update(['status' => TradeOrderStatus::Cancelled, 'external_status' => 'CANCELLING']);

        PortfolioUpdated::dispatch($account);
    }
}
