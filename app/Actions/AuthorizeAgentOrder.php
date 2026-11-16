<?php

namespace App\Actions;

use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
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
     * Tolerance when comparing fractional share quantities, so float rounding cannot deny a sell of exactly the holding.
     */
    private const float QUANTITY_TOLERANCE = 1e-9;

    /**
     * @throws AgentNotAuthorizedException
     */
    public function handle(AiAgent $agent, TradingAccount $account, OrderRequest $order): void
    {
        $this->ensureCanAccessAccount($agent, $account);
        $this->ensureNotExtendedHours($order);
        $this->ensureMayPlaceOrderType($agent, $order);
        $this->ensureMaySell($agent, $order);
        $this->ensureTickerAllowed($agent->role, $order);
        $this->ensureWithinMaxQuantity($agent->role, $order);
        $this->ensureWithinMaxValue($agent->role, $account, $order);
        $this->ensureWithinDailyOrderLimit($agent);
        $this->ensureSellWithinHoldings($account, $order);
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
     * Agents may only trade during regular hours, when prices are liquid; humans may still opt in.
     *
     * @throws AgentNotAuthorizedException
     */
    private function ensureNotExtendedHours(OrderRequest $order): void
    {
        if ($order->extendedHours) {
            $this->deny('AI agents may not place extended-hours orders; omit extended_hours or set it to false.');
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
     * A sell may not exceed the shares that can still be sold, so an agent cannot oversell or open a short.
     *
     * @throws AgentNotAuthorizedException
     */
    private function ensureSellWithinHoldings(TradingAccount $account, OrderRequest $order): void
    {
        if ($order->side !== OrderSide::Sell) {
            return;
        }

        $sellableQuantity = $this->sellableQuantity($account, $order->ticker);

        if ($sellableQuantity <= 0 || $order->quantity > $sellableQuantity + self::QUANTITY_TOLERANCE) {
            $roundedSellable = round($sellableQuantity, 6);

            $this->deny(
                "Sell quantity {$order->quantity} of {$order->ticker} exceeds the {$roundedSellable} share(s) available to sell "
                .'(held shares not in pies, less open sell orders).'
            );
        }
    }

    /**
     * Shares of the ticker that can still be sold: the lower of Trading 212's `quantityAvailableForTrading`
     * (which excludes shares in pies) and the held quantity less the unfilled part of open sell orders.
     *
     * Taking the lower of both is correct whether or not Trading 212 already reserves shares for open sell orders.
     * Every fetch or data problem denies, so the check fails closed.
     *
     * @throws AgentNotAuthorizedException
     */
    private function sellableQuantity(TradingAccount $account, string $ticker): float
    {
        try {
            $positions = $account->client()->positions(fresh: true);
            $pendingOrders = $account->client()->pendingOrders(fresh: true);
        } catch (Trading212Exception) {
            $this->deny("Positions or open orders could not be fetched to check how many shares of {$ticker} can be sold.");
        }

        if (! array_is_list($positions) || ! array_is_list($pendingOrders)) {
            $this->deny("Trading 212 returned malformed positions or open orders, so the sell of {$ticker} cannot be checked.");
        }

        $position = collect($positions)->first(fn (mixed $heldPosition): bool => $this->isSameTicker($this->tickerOf($heldPosition), $ticker));

        if ($position === null) {
            $this->deny("{$ticker} is not held, so it cannot be sold.");
        }

        $heldQuantity = $position['quantity'] ?? null;
        $availableQuantity = $position['quantityAvailableForTrading'] ?? null;

        if (! is_numeric($heldQuantity) || ! is_numeric($availableQuantity)) {
            $this->deny("Trading 212 returned a malformed position for {$ticker}, so the sell cannot be checked.");
        }

        $openSellQuantity = $this->openSellQuantity($pendingOrders, $ticker);

        return max(0.0, min((float) $availableQuantity, (float) $heldQuantity - $openSellQuantity));
    }

    /**
     * The unfilled quantity of every open sell order on the ticker.
     *
     * @param  list<mixed>  $pendingOrders
     *
     * @throws AgentNotAuthorizedException
     */
    private function openSellQuantity(array $pendingOrders, string $ticker): float
    {
        $openSellQuantity = 0.0;

        foreach ($pendingOrders as $pendingOrder) {
            $orderTicker = $this->tickerOf($pendingOrder);

            if ($orderTicker === null) {
                $this->deny("Trading 212 returned a malformed open order, so the sell of {$ticker} cannot be checked.");
            }

            if (! $this->isSameTicker($orderTicker, $ticker)) {
                continue;
            }

            $quantity = $pendingOrder['quantity'] ?? null;
            $filledQuantity = $pendingOrder['filledQuantity'] ?? 0;

            if (! is_numeric($quantity) || ! is_numeric($filledQuantity)) {
                $this->deny("Trading 212 returned a malformed open order for {$ticker}, so the sell cannot be checked.");
            }

            if ($this->isOpenSell($pendingOrder['side'] ?? null, (float) $quantity)) {
                $openSellQuantity += max(0.0, abs((float) $quantity) - abs((float) $filledQuantity));
            }
        }

        return $openSellQuantity;
    }

    /**
     * Whether an open order reserves shares. A side that is missing or not recognised counts as a sell
     * (as does a negative quantity), so unknown orders over-reserve and the check fails closed.
     */
    private function isOpenSell(mixed $side, float $quantity): bool
    {
        $normalisedSide = is_string($side) ? OrderSide::tryFrom(strtoupper(trim($side))) : null;

        return $normalisedSide !== OrderSide::Buy || $quantity < 0;
    }

    /**
     * Trading 212 tickers are compared case-insensitively, so a ticker sent in another case still matches the holding.
     */
    private function isSameTicker(?string $ticker, string $otherTicker): bool
    {
        return $ticker !== null && strcasecmp($ticker, $otherTicker) === 0;
    }

    /**
     * The ticker of a position or order from Trading 212, which may sit at the top level or under `instrument`.
     */
    private function tickerOf(mixed $item): ?string
    {
        if (! is_array($item)) {
            return null;
        }

        $ticker = $item['ticker'] ?? (is_array($item['instrument'] ?? null) ? ($item['instrument']['ticker'] ?? null) : null);

        return is_string($ticker) && $ticker !== '' ? $ticker : null;
    }

    /**
     * The max order value caps buys only, so a position that has grown past the cap can still be exited.
     *
     * @throws AgentNotAuthorizedException
     */
    private function ensureWithinMaxValue(Role $role, TradingAccount $account, OrderRequest $order): void
    {
        if ($role->max_order_value === null || $order->side === OrderSide::Sell) {
            return;
        }

        $value = $this->worstCasePrice($account, $order) * $order->quantity / $this->pricePerPound($account, $order->ticker);

        if ($value > (float) $role->max_order_value) {
            $roundedValue = number_format($value, 2, thousands_separator: '');

            $this->deny("Estimated order value £{$roundedValue} (GBP) exceeds the role's max of £{$role->max_order_value}.");
        }
    }

    /**
     * The highest price per share (in the instrument currency) the order could fill at.
     *
     * A STOP order fills at market once triggered, and a buy stop below the market triggers immediately,
     * so it is valued at the higher of its stop and the held position's current price.
     *
     * @throws AgentNotAuthorizedException
     */
    private function worstCasePrice(TradingAccount $account, OrderRequest $order): float
    {
        return match ($order->type) {
            OrderType::Limit => (float) $order->limitPrice,
            OrderType::StopLimit => max((float) $order->limitPrice, (float) $order->stopPrice),
            OrderType::Market => $this->currentPrice($account, $order->ticker)
                ?? $this->deny('The role has a max order value but the price could not be determined; use a limit order.'),
            OrderType::Stop => max(
                (float) $order->stopPrice,
                $this->currentPrice($account, $order->ticker) ?? $this->deny(
                    "The role has a max order value but the current price of {$order->ticker} could not be determined "
                    .'(it is not held, or positions could not be fetched), so a STOP order is unbounded; use a STOP_LIMIT order.'
                ),
            ),
        };
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
                ->first(fn (mixed $tradableInstrument): bool => is_array($tradableInstrument)
                    && ($tradableInstrument['ticker'] ?? null) === $ticker);
        } catch (Trading212Exception) {
            $this->deny('The role has a max order value but the instrument list could not be fetched to check the currency.');
        }

        if ($instrument === null) {
            $this->deny("The role has a max order value but instrument {$ticker} was not found to check its currency.");
        }

        $currencyCode = is_string($instrument['currencyCode'] ?? null) ? $instrument['currencyCode'] : null;

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
                ->first(fn (mixed $heldPosition): bool => is_array($heldPosition)
                    && is_array($heldPosition['instrument'] ?? null)
                    && ($heldPosition['instrument']['ticker'] ?? null) === $ticker);
        } catch (Trading212Exception) {
            return null;
        }

        $currentPrice = $position['currentPrice'] ?? null;

        return is_numeric($currentPrice) && $currentPrice > 0 ? (float) $currentPrice : null;
    }

    private function deny(string $message): never
    {
        throw new AgentNotAuthorizedException($message);
    }
}
