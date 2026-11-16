<?php

namespace App\Mcp\Tools;

use App\Actions\CancelOrder;
use App\Enums\Permission;
use App\Models\AiAgent;
use App\Models\TradingAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('cancel-order')]
#[Description(
    'Cancel a pending order by its Trading 212 order id (see list-pending-orders). '
    .'Orders that are already filled cannot be cancelled.'
)]
#[IsDestructive]
#[IsIdempotent]
class CancelOrderTool extends TradingTool
{
    protected function requiredPermissions(): array
    {
        return [Permission::CancelOrders];
    }

    protected function toolSchema(JsonSchema $schema): array
    {
        return [
            'order_id' => $schema->integer()->description('The Trading 212 order id to cancel.')->required(),
        ];
    }

    protected function execute(Request $request, AiAgent $agent, ?TradingAccount $account): Response|ResponseFactory
    {
        $validated = $request->validate(['order_id' => ['required', 'integer', 'min:1']]);

        app(CancelOrder::class)->handle($account, (int) $validated['order_id'], $agent);

        return Response::structured([
            'account' => $this->describeAccount($account),
            'cancelled_order_id' => (int) $validated['order_id'],
            'message' => 'Cancellation requested.',
        ]);
    }
}
