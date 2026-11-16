<?php

namespace App\Mcp\Tools;

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

#[Name('list-accounts')]
#[Description(
    'List the Trading 212 accounts you may use, with their ID, name, '
    .'environment (demo = paper money, live = REAL money) and currency. '
    .'The first account is the default used when account_id is omitted.'
)]
#[IsReadOnly]
#[IsIdempotent]
class ListAccountsTool extends TradingTool
{
    protected bool $usesAccount = false;

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
        $accounts = $agent->tradingAccounts()
            ->orderByDesc('is_default')
            ->orderBy('trading_accounts.id')
            ->get()
            ->filter(fn (TradingAccount $tradingAccount): bool => $agent->canAccessAccount($tradingAccount))
            ->values();

        return Response::structured([
            'accounts' => $accounts->map(fn (TradingAccount $tradingAccount, int $index): array => [
                ...$this->describeAccount($tradingAccount),
                'is_default' => $index === 0,
            ])->all(),
        ]);
    }
}
