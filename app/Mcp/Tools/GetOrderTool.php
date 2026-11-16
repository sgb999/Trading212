<?php

namespace App\Mcp\Tools;

use App\Enums\Permission;
use App\Models\AiAgent;
use App\Models\TradingAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-order')]
#[Description('Get a single pending order by its Trading 212 order id (status, type, quantity, prices, fill details).')]
#[IsReadOnly]
#[IsIdempotent]
class GetOrderTool extends TradingTool
{
    protected function requiredPermissions(): array
    {
        return [Permission::ViewOrders];
    }

    protected function toolSchema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->description('The Trading 212 order id.')->required(),
        ];
    }

    protected function execute(Request $request, AiAgent $agent, ?TradingAccount $account): Response|ResponseFactory
    {
        $validated = $request->validate(['order_id' => ['required', 'integer', 'min:1']]);

        return Response::structured([
            'account' => $this->describeAccount($account),
            'order' => $account->client()->order((int) $validated['order_id']),
        ]);
    }
}
