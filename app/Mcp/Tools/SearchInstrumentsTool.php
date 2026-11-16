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

#[Name('search-instruments')]
#[Description(
    'Search tradable instruments by ticker, name, short name or ISIN (e.g. "apple", "AAPL", "US0378331005"). '
    .'Returns the Trading 212 ticker (e.g. "AAPL_US_EQ") needed for place-order, '
    .'plus currency, type and quantity limits.'
)]
#[IsReadOnly]
#[IsIdempotent]
class SearchInstrumentsTool extends TradingTool
{
    protected function requiredPermissions(): array
    {
        return [Permission::ViewInstruments];
    }

    protected function toolSchema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search term: ticker, company name or ISIN.')->required(),
            'limit' => $schema->integer()->min(1)->max(50)->default(25)
                ->description('Maximum number of results (1-50).'),
        ];
    }

    protected function execute(Request $request, AiAgent $agent, ?TradingAccount $account): Response|ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return Response::structured([
            'account' => $this->describeAccount($account),
            'instruments' => $account->client()->searchInstruments(
                $validated['query'],
                (int) ($validated['limit'] ?? 25),
            ),
        ]);
    }
}
