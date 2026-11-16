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

#[Name('get-my-permissions')]
#[Description(
    'Show your agent name, role, granted permissions and trading limits '
    .'(max quantity / value per order, max orders per day, allowed tickers). '
    .'max_order_value is in GBP (pounds) and applies to buys only; see max_order_value_currency. '
    .'Check this before trading to avoid denied requests.'
)]
#[IsReadOnly]
#[IsIdempotent]
class GetMyPermissionsTool extends TradingTool
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
        return Response::structured(self::describe($agent));
    }

    /**
     * @return array{
     *     agent: string,
     *     role: string|null,
     *     permissions: list<array{key: string, label: string}>,
     *     limits: array<string, mixed>,
     * }
     */
    public static function describe(AiAgent $agent): array
    {
        $role = $agent->role;

        return [
            'agent' => $agent->name,
            'role' => $role?->name,
            'permissions' => collect(Permission::cases())
                ->filter(fn (Permission $permission): bool => $agent->hasPermission($permission))
                ->map(fn (Permission $permission): array => [
                    'key' => $permission->value,
                    'label' => $permission->getLabel(),
                ])
                ->values()
                ->all(),
            'limits' => [
                'max_order_quantity' => $role?->max_order_quantity !== null ? (float) $role->max_order_quantity : null,
                'max_order_value' => $role?->max_order_value !== null ? (float) $role->max_order_value : null,
                'max_order_value_currency' => 'GBP',
                'max_orders_per_day' => $role?->max_orders_per_day,
                'allowed_tickers' => blank($role?->allowed_tickers) ? null : $role->allowed_tickers,
            ],
        ];
    }
}
