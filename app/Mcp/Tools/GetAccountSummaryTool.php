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

#[Name('get-account-summary')]
#[Description(
    'Get the account summary: total value, cash (free / blocked / reserved for orders) and investment totals '
    .'(current value, cost basis, realized and unrealized P/L) in the account currency.'
)]
#[IsReadOnly]
#[IsIdempotent]
class GetAccountSummaryTool extends TradingTool
{
    protected function requiredPermissions(): array
    {
        return [Permission::ViewAccount];
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
            'summary' => $account->client()->accountSummary(),
        ]);
    }
}
