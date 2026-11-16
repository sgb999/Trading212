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

#[Name('get-portfolio')]
#[Description(
    'List open positions (instrument, quantity, average price paid, current price, value and P/L). '
    .'Optionally filter to a single Trading 212 ticker such as "AAPL_US_EQ".'
)]
#[IsReadOnly]
#[IsIdempotent]
class GetPortfolioTool extends TradingTool
{
    protected function requiredPermissions(): array
    {
        return [Permission::ViewPortfolio];
    }

    protected function toolSchema(JsonSchema $schema): array
    {
        return [
            'ticker' => $schema->string()->description('Optional Trading 212 ticker to filter by, e.g. "AAPL_US_EQ".'),
        ];
    }

    protected function execute(Request $request, AiAgent $agent, ?TradingAccount $account): Response|ResponseFactory
    {
        $validated = $request->validate(['ticker' => ['nullable', 'string', 'max:64']]);

        return Response::structured([
            'account' => $this->describeAccount($account),
            'positions' => $account->client()->positions($validated['ticker'] ?? null),
        ]);
    }
}
