<?php

namespace App\Actions;

use App\Data\OrderRequest;
use App\Enums\TradeOrderStatus;
use App\Events\OrderPlaced;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\Setting;
use App\Models\TradeOrder;
use App\Models\TradingAccount;
use App\Models\User;
use App\Services\Trading212\Exceptions\Trading212Exception;
use Illuminate\Support\Facades\Log;

/**
 * Places an order on Trading 212 on behalf of a User or an AiAgent, recording an audit TradeOrder.
 *
 * Agents are checked against their role first. Failed API calls are recorded with status
 * `failed` and the exception is re-thrown.
 */
class PlaceOrder
{
    public function __construct(private readonly AuthorizeAgentOrder $authorizeAgentOrder) {}

    /**
     * @throws AgentNotAuthorizedException
     * @throws Trading212Exception
     */
    public function handle(TradingAccount $account, OrderRequest $order, User|AiAgent $actor): TradeOrder
    {
        if ($actor instanceof AiAgent) {
            if (Setting::isAgentTradingHalted()) {
                Log::warning('Agent order denied: AI agent trading is halted by the kill switch.', [
                    'ai_agent_id' => $actor->getKey(),
                    'trading_account_id' => $account->getKey(),
                    'ticker' => $order->ticker,
                ]);

                throw new AgentNotAuthorizedException('AI agent trading is halted by the global kill switch.');
            }

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
                'status' => TradeOrderStatus::Failed,
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
}
