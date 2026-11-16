<?php

namespace App\Mcp\Tools;

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\Permission;
use App\Enums\TimeValidity;
use App\Models\AiAgent;
use App\Models\TradingAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('place-order')]
#[Description(<<<'TEXT'
    Place a BUY or SELL order on a Trading 212 account.
    Always pass a POSITIVE quantity; use side=SELL to sell (it is sent to Trading 212 as a negative quantity).
    type: MARKET (executes immediately at market price; extended_hours allowed), LIMIT (requires limit_price),
    STOP (requires stop_price), STOP_LIMIT (requires both). Do not send a price the order type does not use.
    limit_price and stop_price are in the INSTRUMENT's currency (see currencyCode from search-instruments):
    LSE shares quoted in GBX are priced in PENCE (412 = £4.12), not pounds.
    A role's max order value is in GBP; with one set, only GBP and GBX instruments may be traded, and orders are
    valued at their worst-case price: MARKET at the held position's current price, LIMIT at the limit,
    STOP_LIMIT at the higher of limit and stop, STOP at the higher of stop and current price (STOP needs a held
    position for that; otherwise use STOP_LIMIT).
    time_validity (DAY or GOOD_TILL_CANCEL) applies to non-market orders. On a LIVE account this trades REAL money.
    Your role's permissions and limits (see get-my-permissions) are enforced; violations are rejected and logged.
    TEXT)]
#[IsDestructive]
class PlaceOrderTool extends TradingTool
{
    protected function requiredPermissions(): array
    {
        return [Permission::PlaceMarketOrders, Permission::PlaceLimitOrders, Permission::PlaceStopOrders];
    }

    protected function toolSchema(JsonSchema $schema): array
    {
        return [
            'ticker' => $schema->string()
                ->description('Trading 212 ticker, e.g. "AAPL_US_EQ" (use search-instruments to find it).')
                ->required(),
            'type' => $schema->string()->enum(OrderType::class)->description('Order type.')->required(),
            'side' => $schema->string()->enum(OrderSide::class)->description('BUY or SELL.')->required(),
            'quantity' => $schema->number()->min(0)
                ->description(
                    'Number of shares '
                    .'(positive; fractional allowed for market orders where the instrument supports it).'
                )
                ->required(),
            'limit_price' => $schema->number()->min(0)
                ->description('Limit price per share, in the instrument currency (pence for GBX). Required for LIMIT and STOP_LIMIT.'),
            'stop_price' => $schema->number()->min(0)
                ->description('Stop (trigger) price per share, in the instrument currency (pence for GBX). Required for STOP and STOP_LIMIT.'),
            'time_validity' => $schema->string()
                ->enum(TimeValidity::class)
                ->default(TimeValidity::Day->value)
                ->description('How long a non-market order stays active.'),
            'extended_hours' => $schema->boolean()
                ->default(false)
                ->description('MARKET orders only: allow execution outside regular trading hours.'),
        ];
    }

    protected function execute(Request $request, AiAgent $agent, ?TradingAccount $account): Response|ResponseFactory
    {
        $validated = $request->validate([
            'ticker' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', Rule::enum(OrderType::class)],
            'side' => ['required', 'string', Rule::enum(OrderSide::class)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'limit_price' => ['nullable', 'numeric', 'gt:0', 'prohibited_unless:type,LIMIT,STOP_LIMIT'],
            'stop_price' => ['nullable', 'numeric', 'gt:0', 'prohibited_unless:type,STOP,STOP_LIMIT'],
            'time_validity' => ['nullable', 'string', Rule::enum(TimeValidity::class)],
            'extended_hours' => ['nullable', 'boolean'],
        ]);

        $tradeOrder = app(PlaceOrder::class)->handle($account, OrderRequest::fromArray($validated), $agent);

        return Response::structured([
            'account' => $this->describeAccount($account),
            'order' => [
                'id' => $tradeOrder->external_id,
                'local_id' => $tradeOrder->getKey(),
                'status' => $tradeOrder->status->value,
                'external_status' => $tradeOrder->external_status,
                'ticker' => $tradeOrder->ticker,
                'type' => $tradeOrder->type->value,
                'side' => $tradeOrder->side->value,
                'quantity' => (float) $tradeOrder->quantity,
                'limit_price' => $tradeOrder->limit_price !== null ? (float) $tradeOrder->limit_price : null,
                'stop_price' => $tradeOrder->stop_price !== null ? (float) $tradeOrder->stop_price : null,
            ],
        ]);
    }
}
