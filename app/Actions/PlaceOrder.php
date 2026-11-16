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
use App\Services\Trading212\Exceptions\OrderOutcomeUnknownException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Exceptions\UnresolvedOrderException;

/**
 * Places an order on Trading 212 on behalf of a User or an AiAgent, recording an audit TradeOrder.
 *
 * Orders on a ticker that still has an unresolved (`sending` or `unknown`) order on the account are
 * refused for every actor, before anything is sent. Agents are then checked against their role; a
 * denial writes no TradeOrder. The TradeOrder is then saved as `sending` before the request goes
 * out, so an in-flight order (or one whose process died mid-request) already counts toward the daily
 * limit and blocks its ticker. The result then sets the final status: a rejection is recorded as
 * `failed` and re-thrown; no response or a 5xx is recorded as `unknown` and re-thrown as an
 * {@see OrderOutcomeUnknownException}. A success without an order id is also kept `unknown` (and
 * reported through the same exception, after {@see OrderPlaced}), so the sync job matches it like any
 * other unconfirmed order instead of it holding a remote order unclaimed. The result is written only
 * while the row is still `sending`: if the sync already reconciled it during a slow request, its
 * outcome is kept.
 */
class PlaceOrder
{
    public function __construct(private readonly AuthorizeAgentOrder $authorizeAgentOrder) {}

    /**
     * @throws AgentNotAuthorizedException
     * @throws UnresolvedOrderException
     * @throws OrderOutcomeUnknownException
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
            'status' => TradeOrderStatus::Sending,
        ]);
        $tradeOrder->tradingAccount()->associate($account);
        $tradeOrder->placedBy()->associate($actor);
        $tradeOrder->save();

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
            $outcomeIsUnknown = $e->leavesOutcomeUnknown();

            $tradeOrder->updateIfStatusIn([TradeOrderStatus::Sending], [
                'status' => $outcomeIsUnknown ? TradeOrderStatus::Unknown : TradeOrderStatus::Failed,
                'error' => $e->getMessage(),
                'response' => $e->body,
            ]);

            if ($outcomeIsUnknown) {
                throw OrderOutcomeUnknownException::fromRequestFailure($order->ticker, $tradeOrder->getKey(), $e);
            }

            throw $e;
        }

        $externalId = filter_var($response['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        $wasRecorded = $tradeOrder->updateIfStatusIn([TradeOrderStatus::Sending], [
            'external_id' => $externalId === false ? null : $externalId,
            'external_status' => $response['status'] ?? null,
            'status' => match (true) {
                $externalId === false => TradeOrderStatus::Unknown,
                ($response['status'] ?? null) === 'FILLED' => TradeOrderStatus::Filled,
                default => TradeOrderStatus::Submitted,
            },
            'error' => $externalId === false
                ? 'Trading 212 accepted the request but returned no order id; it will be matched at the next sync.'
                : null,
            'response' => $response,
        ]);

        OrderPlaced::dispatch($tradeOrder);

        if ($externalId === false && $wasRecorded) {
            throw OrderOutcomeUnknownException::missingOrderId($order->ticker, $tradeOrder->getKey(), $response);
        }

        return $tradeOrder;
    }

    /**
     * Refuses a new order while an earlier order on the same ticker (compared case-insensitively)
     * and account is still being sent or has an unknown outcome, so it cannot be duplicated by a retry.
     *
     * @throws AgentNotAuthorizedException
     * @throws UnresolvedOrderException
     */
    private function ensureNoUnresolvedOrderForTicker(TradingAccount $account, OrderRequest $order, User|AiAgent $actor): void
    {
        $hasUnresolvedOrder = $account->tradeOrders()
            ->whereIn('status', TradeOrderStatus::unresolved())
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
