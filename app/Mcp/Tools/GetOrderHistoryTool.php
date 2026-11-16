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

#[Name('get-order-history')]
#[Description(
    'Get historical (filled, cancelled, rejected) orders, newest first, with fill price and value. '
    .'Paginated: pass the returned next_cursor as cursor to fetch the next page (null = no more pages).'
)]
#[IsReadOnly]
#[IsIdempotent]
class GetOrderHistoryTool extends TradingTool
{
    protected function requiredPermissions(): array
    {
        return [Permission::ViewHistory];
    }

    protected function toolSchema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()->description('Pagination cursor from a previous call (next_cursor).'),
            'ticker' => $schema->string()->description('Optional Trading 212 ticker to filter by, e.g. "AAPL_US_EQ".'),
            'limit' => $schema->integer()->min(1)->max(50)->default(20)->description('Items per page (1-50).'),
        ];
    }

    protected function execute(Request $request, AiAgent $agent, ?TradingAccount $account): Response|ResponseFactory
    {
        $validated = $request->validate([
            'cursor' => ['nullable', 'string', 'max:255'],
            'ticker' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return Response::structured([
            'account' => $this->describeAccount($account),
            ...$this->historyPage($account->client()->orderHistory(
                $validated['cursor'] ?? null,
                $validated['ticker'] ?? null,
                (int) ($validated['limit'] ?? 20),
            )),
        ]);
    }
}
