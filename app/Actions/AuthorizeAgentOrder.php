<?php

namespace App\Actions;

use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\Permission;
use App\Enums\TradeOrderStatus;
use App\Exceptions\AgentNotAuthorizedException;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\Trading212Exception;

/**
 * Checks an AI agent's role (permissions + limits) against an order before it is sent.
 */
class AuthorizeAgentOrder
{
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
        $this->ensureWithinMaxValue($agent->role, $account, $order);
        $this->ensureWithinDailyOrderLimit($agent);
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

        $value = round($price * $order->quantity / $this->pricePerPound($account, $order->ticker), 2);

        if ($value > (float) $role->max_order_value) {
            $this->deny("Estimated order value £{$value} (GBP) exceeds the role's max of £{$role->max_order_value}.");
        }
    }

    /**
     * How many units of the instrument's quote currency make one pound: 100 for GBX (pence), 1 for GBP.
     *
     * Prices from Trading 212 are in the instrument's currency. Any other currency is denied rather than converted.
     *
     * @throws AgentNotAuthorizedException
     */
    private function pricePerPound(TradingAccount $account, string $ticker): int
    {
        try {
            $instrument = collect($account->client()->instruments())
                ->first(fn (array $tradableInstrument): bool => ($tradableInstrument['ticker'] ?? null) === $ticker);
        } catch (Trading212Exception) {
            $this->deny('The role has a max order value but the instrument list could not be fetched to check the currency.');
        }

        if ($instrument === null) {
            $this->deny("The role has a max order value but instrument {$ticker} was not found to check its currency.");
        }

        $currencyCode = $instrument['currencyCode'] ?? null;

        return match ($currencyCode) {
            'GBX' => 100,
            'GBP' => 1,
            null => $this->deny("The role has a max order value but the currency of {$ticker} is unknown."),
            default => $this->deny(
                "The role has a max order value in GBP but {$ticker} is quoted in {$currencyCode}; only GBP and GBX instruments are allowed."
            ),
        };
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
