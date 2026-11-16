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
use App\Services\Trading212\Exceptions\OrderInProgressException;
use App\Services\Trading212\Exceptions\OrderOutcomeUnknownException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Exceptions\UnresolvedOrderException;
use App\Services\Trading212\Trading212Client;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use LogicException;

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
 *
 * All of the above (the unresolved-ticker check, authorisation, saving the `sending` row, the API
 * call and the final update) runs under a per-account cache lock, so two concurrent orders cannot
 * both pass the daily limit, a balance or holdings check, or the unresolved-ticker check before
 * either is saved. When the lock cannot be taken within {@see self::LOCK_WAIT_SECONDS} the order is
 * refused, never queued or retried.
 *
 * The lock's lifetime ({@see self::lockTtlSeconds()}) covers the worst case of every budgeted API
 * call, but it can still lapse if a placement makes more calls than budgeted or the process stalls.
 * Ownership is therefore re-checked after authorisation: if the lock lapsed (another order may have
 * taken it), this order is refused before its `sending` row is saved or anything is sent. Once the
 * `sending` row exists it blocks its ticker and counts toward the daily limit without the lock, so a
 * lapse during the POST cannot let a second order on the same ticker through. It does not protect a
 * different ticker: a second order on another ticker could then be authorised against an account
 * summary or positions that do not yet reflect the in-flight order.
 */
class PlaceOrder
{
    /**
     * Seconds to wait for another order on the same account to finish before refusing this one.
     */
    public const int LOCK_WAIT_SECONDS = 5;

    /**
     * Attempts {@see Trading212Client} makes per API call: the first, plus one retry after a 429.
     */
    private const int ATTEMPTS_PER_API_CALL = 2;

    public function __construct(private readonly AuthorizeAgentOrder $authorizeAgentOrder) {}

    /**
     * @throws AgentNotAuthorizedException
     * @throws OrderInProgressException
     * @throws UnresolvedOrderException
     * @throws OrderOutcomeUnknownException
     * @throws Trading212Exception
     */
    public function handle(TradingAccount $account, OrderRequest $order, User|AiAgent $actor): TradeOrder
    {
        $lock = self::lockFor($account);

        try {
            return $lock->block(
                self::LOCK_WAIT_SECONDS,
                fn (): TradeOrder => $this->authorizeAndPlace($account, $order, $actor, $lock),
            );
        } catch (LockTimeoutException) {
            $this->refuseAsInProgress($actor, OrderInProgressException::message());
        }
    }

    /**
     * The cache lock key that serialises order placement on an account.
     */
    public static function lockKey(TradingAccount $account): string
    {
        return "trade-order:place:account:{$account->getKey()}";
    }

    /**
     * The account's order lock on the trading212 cache store, which must support atomic locks.
     */
    private static function lockFor(TradingAccount $account): Lock
    {
        $store = Cache::store(config('trading212.cache_store'))->getStore();
        $lock = $store instanceof LockProvider ? $store->lock(self::lockKey($account), self::lockTtlSeconds()) : null;

        if (! $lock instanceof Lock) {
            throw new LogicException('The trading212 cache store must support atomic locks.');
        }

        return $lock;
    }

    /**
     * Seconds the account lock lives, covering the worst case while it is held:
     *
     *     max_api_calls x ATTEMPTS_PER_API_CALL x (max_wait_seconds + timeout) + margin_seconds
     *
     * Each API call may wait up to `trading212.max_wait_seconds` for a rate-limit slot and then up
     * to `trading212.timeout` for the response, on each of its attempts. `trading212.order_lock.
     * max_api_calls` budgets every call authorisation and placement can make (price, positions,
     * pending orders and account summary lookups, plus the placement itself), and the margin covers
     * the database work. With the defaults: 6 x 2 x (10 + 15) + 60 = 360 seconds.
     */
    public static function lockTtlSeconds(): int
    {
        $secondsPerAttempt = (int) config('trading212.max_wait_seconds') + (int) config('trading212.timeout');

        return (int) config('trading212.order_lock.max_api_calls') * self::ATTEMPTS_PER_API_CALL * $secondsPerAttempt
            + (int) config('trading212.order_lock.margin_seconds');
    }

    /**
     * @throws AgentNotAuthorizedException
     * @throws OrderInProgressException
     * @throws UnresolvedOrderException
     * @throws OrderOutcomeUnknownException
     * @throws Trading212Exception
     */
    private function authorizeAndPlace(TradingAccount $account, OrderRequest $order, User|AiAgent $actor, Lock $lock): TradeOrder
    {
        $this->authorizeUnderLock($account, $order, $actor, $lock);

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
     * Runs the unresolved-ticker check and, for agents, role authorisation, then confirms the lock
     * did not lapse meanwhile, so nothing is saved or sent once another order could hold it.
     *
     * @throws AgentNotAuthorizedException
     * @throws OrderInProgressException
     * @throws UnresolvedOrderException
     * @throws Trading212Exception
     */
    private function authorizeUnderLock(TradingAccount $account, OrderRequest $order, User|AiAgent $actor, Lock $lock): void
    {
        $this->ensureNoUnresolvedOrderForTicker($account, $order, $actor);

        if ($actor instanceof AiAgent) {
            $this->authorizeAgentOrder->handle($actor, $account, $order);
        }

        if (! $lock->isOwnedByCurrentProcess()) {
            $this->refuseAsInProgress($actor, OrderInProgressException::lockLapsedMessage());
        }
    }

    /**
     * Agents get an {@see AgentNotAuthorizedException} so the refusal is logged as a denial.
     *
     * @throws AgentNotAuthorizedException
     * @throws OrderInProgressException
     */
    private function refuseAsInProgress(User|AiAgent $actor, string $message): never
    {
        if ($actor instanceof AiAgent) {
            throw new AgentNotAuthorizedException($message);
        }

        throw new OrderInProgressException($message);
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
