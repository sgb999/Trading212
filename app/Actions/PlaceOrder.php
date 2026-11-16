<?php

namespace App\Actions;

use App\Data\OrderRequest;
use App\Enums\TradeOrderStatus;
use App\Events\OrderPlaced;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Exceptions\UnresolvedOrderException;

/**
 * Places an order on Trading 212 on behalf of a User or an AiAgent, recording an audit TradeOrder.
 *
 * Orders on a ticker that still has an `unknown` order on the account are refused for every actor,
 * before anything is sent. Agents are then checked against their role. Failed API calls are recorded
 * with status `failed`, or `unknown` when Trading 212 may still have accepted the order (no response
 * or a 5xx), and the exception is re-thrown.
 */
class PlaceOrder
{
    public function __construct(private readonly AuthorizeAgentOrder $authorizeAgentOrder) {}

    /**
     * @throws AgentNotAuthorizedException
     * @throws UnresolvedOrderException
     * @throws Trading212Exception
     */
    public function handle(TradingAccount $account, OrderRequest $order, User|AiAgent $actor): TradeOrder
    {
        $this->ensureNoUnresolvedOrderForTicker($account, $order, $actor);

        if ($actor instanceof AiAgent) {
            $this->authorizeAgentOrder->handle($actor, $account, $order);
        }

        $tradeOrder = new TradeOrder([
            'ticker' => $order->ticker,
            'type' => $order->type,
            'side' => $order->side,
            'quantity' => $order->quantity,
            'limit_price' => $order->limitPrice,
            'stop_price' => $order->stopPrice,
            'time_validity' => $order->type->value === 'MARKET' ? null : $order->timeValidity,
            'extended_hours' => $order->extendedHours,
            'status' => TradeOrderStatus::Submitted,
        ]);
        $tradeOrder->tradingAccount()->associate($account);
        $tradeOrder->placedBy()->associate($actor);

        try {
            $response = $account->client()->placeOrder(
                type: $order->type,
                ticker: $order->ticker,
                quantity: $order->signedQuantity(),
                limitPrice: $order->limitPrice,
                stopPrice: $order->stopPrice,
                timeValidity: $order->timeValidity,
                extendedHours: $order->extendedHours,
            );
        } catch (Trading212Exception $e) {
            $tradeOrder->fill([
                'status' => $e->leavesOutcomeUnknown() ? TradeOrderStatus::Unknown : TradeOrderStatus::Failed,
                'error' => $e->getMessage(),
                'response' => $e->body,
            ])->save();

            throw $e;
        }

        $tradeOrder->fill([
            'external_id' => $response['id'] ?? null,
            'external_status' => $response['status'] ?? null,
            'status' => ($response['status'] ?? null) === 'FILLED'
                ? TradeOrderStatus::Filled
                : TradeOrderStatus::Submitted,
            'response' => $response,
        ])->save();

        OrderPlaced::dispatch($tradeOrder);

        return $tradeOrder;
    }

    /**
     * Refuses a new order while an earlier order on the same ticker (compared case-insensitively)
     * and account has an unknown outcome, so a timed-out order cannot be duplicated by a retry.
     *
     * @throws AgentNotAuthorizedException
     * @throws UnresolvedOrderException
     */
    private function ensureNoUnresolvedOrderForTicker(TradingAccount $account, OrderRequest $order, User|AiAgent $actor): void
    {
        $hasUnresolvedOrder = $account->tradeOrders()
            ->where('status', TradeOrderStatus::Unknown)
            ->whereRaw('LOWER(ticker) = LOWER(?)', [$order->ticker])
            ->exists();

        if (! $hasUnresolvedOrder) {
            return;
        }

        if ($actor instanceof AiAgent) {
            throw new AgentNotAuthorizedException(UnresolvedOrderException::messageFor($order->ticker));
        }

        throw new UnresolvedOrderException($order->ticker);
    }
}
