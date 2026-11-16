<?php

namespace App\Mcp\Tools;

use App\Enums\Permission;
use App\Models\AiAgent;
use App\Models\TradingAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;

#[Name('list-pending-orders')]
#[Description(
    'List pending (working, not yet filled) orders. Sell orders have a negative quantity. '
    .'Use the order id with get-order or cancel-order.'
)]
#[IsReadOnly]
#[IsIdempotent]
class ListPendingOrdersTool extends TradingTool
{
    protected function requiredPermissions(): array
    {
        return [Permission::ViewOrders];
    }

    /**
     * @return array<string, Type>
     */
    #[Override]
    protected function toolSchema(JsonSchema $schema): array
    {
        return [];
    }

    protected function execute(Request $request, AiAgent $agent, ?TradingAccount $account): Response|ResponseFactory
    {
        return Response::structured([
            'account' => $this->describeAccount($account),
            'orders' => $account->client()->pendingOrders(),
        ]);
    }
}
