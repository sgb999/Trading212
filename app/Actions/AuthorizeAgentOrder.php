<?php

namespace App\Actions;

use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TimeValidity;
use App\Enums\TradeOrderStatus;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\PortfolioSnapshot;
use App\Models\Role;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\Trading212Exception;
use UnexpectedValueException;

/**
 * Checks an AI agent's role (permissions + limits) against an order before it is sent.
 */
class AuthorizeAgentOrder
{
    public function __construct(private readonly RecordPortfolioSnapshot $recordPortfolioSnapshot) {}

    /**
     * @throws AgentNotAuthorizedException
     */
    public function handle(AiAgent $agent, TradingAccount $account, OrderRequest $order): void
    {
        $this->ensureCanAccessAccount($agent, $account);
        $this->ensureMayPlaceOrderType($agent, $order);
        $this->ensureMaySell($agent, $order);
        $this->ensureTickerAllowed($agent->role, $order);
        $this->ensureWithinMaxQuantity($agent->role, $order);
        $lossGuardReference = $this->lossGuardReference($agent->role, $account, $order);
        $this->ensureWithinMaxValue($agent->role, $account, $order);
        $this->ensureWithinDailyOrderLimit($agent);

        if ($lossGuardReference !== null) {
            $this->ensureWithinLossGuards($agent->role, $account, $lossGuardReference);
        }
    }

    /**
     * @throws AgentNotAuthorizedException
     */
    private function ensureCanAccessAccount(AiAgent $agent, TradingAccount $account): void
    {
        if (! $agent->canAccessAccount($account)) {
            $this->deny("Agent is not allowed to act on account [{$account->name}].");
        }
    }

    /**
     * @throws AgentNotAuthorizedException
     */
    private function ensureMayPlaceOrderType(AiAgent $agent, OrderRequest $order): void
    {
        if (! $agent->hasPermission($order->type->permission())) {
            $this->deny("Role [{$agent->role->name}] may not place {$order->type->getLabel()} orders.");
        }
    }

    /**
     * @throws AgentNotAuthorizedException
     */
    private function ensureMaySell(AiAgent $agent, OrderRequest $order): void
    {
        if ($order->side === OrderSide::Sell && ! $agent->hasPermission(Permission::Sell)) {
            $this->deny("Role [{$agent->role->name}] may not sell.");
        }
    }

    /**
     * @throws AgentNotAuthorizedException
     */
    private function ensureTickerAllowed(Role $role, OrderRequest $order): void
    {
        if (! $role->allowsTicker($order->ticker)) {
            $this->deny("Role [{$role->name}] may not trade {$order->ticker}.");
        }
    }

    /**
     * @throws AgentNotAuthorizedException
     */
    private function ensureWithinMaxQuantity(Role $role, OrderRequest $order): void
    {
        if ($role->max_order_quantity !== null && $order->quantity > (float) $role->max_order_quantity) {
            $this->deny("Quantity {$order->quantity} exceeds the role's max of {$role->max_order_quantity}.");
        }
    }

    /**
     * @throws AgentNotAuthorizedException
     */
    private function ensureWithinMaxValue(Role $role, TradingAccount $account, OrderRequest $order): void
    {
        if ($role->max_order_value === null) {
            return;
        }

        $price = $order->knownPrice() ?? $this->currentPrice($account, $order->ticker);

        if ($price === null) {
            $this->deny('The role has a max order value but the price could not be determined; use a limit order.');
        }

        $value = round($price * $order->quantity, 2);

        if ($value > (float) $role->max_order_value) {
            $this->deny("Estimated order value {$value} exceeds the role's max of {$role->max_order_value}.");
        }
    }

    /**
     * @throws AgentNotAuthorizedException
     */
    private function ensureWithinDailyOrderLimit(AiAgent $agent): void
    {
        $role = $agent->role;

        if ($role->max_orders_per_day === null) {
            return;
        }

        $ordersToday = $agent->tradeOrders()
            ->where('created_at', '>=', now()->startOfDay())
            ->where('status', '!=', TradeOrderStatus::Failed)
            ->count();

        if ($ordersToday >= $role->max_orders_per_day) {
            $this->deny("Daily order limit of {$role->max_orders_per_day} reached.");
        }
    }

    /**
     * The DB-only half of the loss guards on agent BUY orders, run before any check that calls the API.
     * SELLs always pass, so an agent can still reduce risk. Returns null when no loss guard applies.
     *
     * When `max_daily_loss_percent` or `min_portfolio_value` is set, a BUY is denied when:
     * - it is a limit, stop or stop-limit order whose time validity is not DAY. A GOOD_TILL_CANCEL order
     *   placed while the account is healthy would otherwise still fill after it breaches the floor.
     *   {@see OrderRequest} defaults a missing validity to DAY and the client always sends it for
     *   non-market orders, so Trading 212's own default never applies;
     * - there is no portfolio snapshot today (Europe/London, {@see TradingAccount::firstSnapshotToday()});
     * - a reference snapshot is not in GBP.
     *
     * The reference value is max(first snapshot today, last snapshot before it). When the morning syncs
     * fail, today's first snapshot may be taken after a drop; the previous day's last snapshot still holds
     * the higher value, so the drop is not hidden. A drop overnight therefore also counts towards today's loss.
     *
     * @return array{referenceValue: float, externalAccountId: int}|null
     *
     * @throws AgentNotAuthorizedException
     */
    private function lossGuardReference(Role $role, TradingAccount $account, OrderRequest $order): ?array
    {
        if ($order->side !== OrderSide::Buy || ! $this->hasLossGuards($role)) {
            return null;
        }

        if ($order->type !== OrderType::Market && $order->timeValidity !== TimeValidity::Day) {
            $this->deny("Role [{$role->name}] has loss guards, so {$order->type->getLabel()} BUY orders must use "
                .'DAY time validity; a pending order could otherwise fill after the account breaches its floor.');
        }

        $firstSnapshotToday = $account->firstSnapshotToday();

        if ($firstSnapshotToday === null) {
            $this->deny('Loss guards are set but there is no portfolio snapshot for today (Europe/London) yet, '
                .'so BUY orders are blocked until the account syncs. SELL orders are still allowed.');
        }

        return [
            'referenceValue' => $this->dayReferenceValue($account, $firstSnapshotToday),
            'externalAccountId' => (int) $firstSnapshotToday->external_account_id,
        ];
    }

    /**
     * The API half of the loss guards, run last. A BUY is denied when the current value cannot be fetched
     * or is invalid (fail closed), is more than `max_daily_loss_percent` below the day's reference value, or
     * is below `min_portfolio_value`.
     *
     * The current value comes from a fresh account summary, not the latest snapshot: snapshots are
     * throttled to one every 15 minutes and stop when syncs fail, so the latest one can hide a recent drop.
     *
     * Deposits and withdrawals move the total value too: a withdrawal counts as a loss for the rest of the day.
     *
     * @param  array{referenceValue: float, externalAccountId: int}  $reference
     *
     * @throws AgentNotAuthorizedException
     */
    private function ensureWithinLossGuards(Role $role, TradingAccount $account, array $reference): void
    {
        $maxLossPercent = $role->max_daily_loss_percent;
        $minPortfolioValue = $role->min_portfolio_value;
        $referenceValue = $reference['referenceValue'];
        $currentValue = $this->currentPortfolioValue($account, $reference['externalAccountId']);
        $current = number_format($currentValue, 2, '.', '');

        if ($maxLossPercent !== null) {
            $floorValue = round($referenceValue * (100 - (float) $maxLossPercent) / 100, 2);

            if ($currentValue < $floorValue) {
                $lossPercent = number_format(($referenceValue - $currentValue) / $referenceValue * 100, 2, '.', '');

                $this->deny("Portfolio value £{$current} is down {$lossPercent}% from today's reference of £"
                    .number_format($referenceValue, 2, '.', '')
                    ." (max daily loss {$maxLossPercent}%, floor £".number_format($floorValue, 2, '.', '')
                    .'); BUY orders are blocked. SELL orders are still allowed.');
            }
        }

        if ($minPortfolioValue !== null && $currentValue < (float) $minPortfolioValue) {
            $this->deny("Portfolio value £{$current} is below the role's minimum of £{$minPortfolioValue}; "
                .'BUY orders are blocked. SELL orders are still allowed.');
        }
    }

    private function hasLossGuards(Role $role): bool
    {
        return $role->max_daily_loss_percent !== null || $role->min_portfolio_value !== null;
    }

    /**
     * max(first snapshot today, the last snapshot before it) of the same Trading 212 account, in GBP.
     * "Before it" needs no day boundary: today's first snapshot is the earliest of the London day.
     *
     * @throws AgentNotAuthorizedException
     */
    private function dayReferenceValue(TradingAccount $account, PortfolioSnapshot $firstSnapshotToday): float
    {
        $previousSnapshot = $account->portfolioSnapshots()
            ->where('external_account_id', $firstSnapshotToday->external_account_id)
            ->where('taken_at', '<', $firstSnapshotToday->taken_at)
            ->latest('taken_at')
            ->latest('id')
            ->first();

        $snapshots = array_filter([$firstSnapshotToday, $previousSnapshot]);

        foreach ($snapshots as $snapshot) {
            if ($snapshot->currency !== 'GBP') {
                $this->deny("Loss guards compare values in GBP but the portfolio snapshot is in [{$snapshot->currency}]; "
                    .'BUY orders are blocked.');
            }
        }

        return max(array_map(fn (PortfolioSnapshot $snapshot): float => (float) $snapshot->total_value, $snapshots));
    }

    /**
     * The account's current total value in GBP from a fresh summary, rounded to pence. Denies when it cannot
     * be fetched (including when rate limited), is not a positive number, does not match cash + invested
     * ({@see RecordPortfolioSnapshot::validatedAttributes()}), is not in GBP, or belongs to another
     * Trading 212 account.
     *
     * @throws AgentNotAuthorizedException
     */
    private function currentPortfolioValue(TradingAccount $account, int $externalAccountId): float
    {
        $failure = 'Loss guards are set but the current portfolio value could not be determined';

        try {
            $summary = $account->client()->accountSummary(fresh: true);
        } catch (Trading212Exception $e) {
            $this->deny("{$failure} ({$e->getMessage()}); BUY orders are blocked.");
        }

        $totalValue = $summary['totalValue'] ?? null;
        $currency = $summary['currency'] ?? $account->currency;

        if (! is_numeric($totalValue) || ! is_finite((float) $totalValue) || (float) $totalValue <= 0) {
            $this->deny("{$failure} (invalid totalValue); BUY orders are blocked.");
        }

        try {
            $this->recordPortfolioSnapshot->validatedAttributes($account, $summary);
        } catch (UnexpectedValueException $e) {
            $this->deny("{$failure} ({$e->getMessage()}); BUY orders are blocked.");
        }

        if ($currency !== 'GBP') {
            $currencyLabel = is_string($currency) ? $currency : 'unknown';

            $this->deny("Loss guards compare values in GBP but the account summary is in [{$currencyLabel}]; "
                .'BUY orders are blocked.');
        }

        try {
            $summaryAccountId = $this->recordPortfolioSnapshot->externalAccountId($summary);
        } catch (UnexpectedValueException) {
            $summaryAccountId = null;
        }

        if ($summaryAccountId !== $externalAccountId) {
            $this->deny("{$failure} (the summary is for a different Trading 212 account than today's snapshot); "
                .'BUY orders are blocked.');
        }

        return round((float) $totalValue, 2);
    }

    private function currentPrice(TradingAccount $account, string $ticker): ?float
    {
        try {
            $position = collect($account->client()->positions())
                ->first(fn (array $heldPosition): bool => ($heldPosition['instrument']['ticker'] ?? null) === $ticker);
        } catch (Trading212Exception) {
            return null;
        }

        return isset($position['currentPrice']) ? (float) $position['currentPrice'] : null;
    }

    private function deny(string $message): never
    {
        throw new AgentNotAuthorizedException($message);
    }
}
