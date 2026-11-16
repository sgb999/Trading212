<?php

namespace App\Mcp\Prompts;

use App\Enums\Permission;
use App\Mcp\CurrentAgent;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Prompts\Argument;

#[Name('portfolio-review')]
#[Description(
    'Review a Trading 212 portfolio: allocation, concentration, P/L, cash and pending orders, with suggestions. '
    .'Read-only — it never places orders.'
)]
class PortfolioReviewPrompt extends Prompt
{
    public function shouldRegister(CurrentAgent $currentAgent): bool
    {
        return $currentAgent->get()?->hasPermission(Permission::ViewPortfolio) === true;
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'account_id' => ['nullable', 'integer', 'min:1'],
            'focus' => ['nullable', 'string', 'max:500'],
        ]);

        $account = isset($validated['account_id'])
            ? "the account with account_id {$validated['account_id']}"
            : 'my default account';

        $focus = filled($validated['focus'] ?? null) ? "\n\nPay particular attention to: {$validated['focus']}" : '';

        return Response::text(<<<TEXT
            Please review the Trading 212 portfolio of {$account}.

            1. Call list-accounts to confirm the account and whether it is demo or live.
            2. Call get-account-summary for total value, free cash and P/L.
            3. Call get-portfolio for open positions; compute each position's weight of total value.
            4. Call list-pending-orders to see working orders.
            5. Summarise: allocation and concentration risk (any position > 20%), biggest winners/losers, cash drag,
               and pending orders that look stale.
            6. Suggest (but do NOT place) any rebalancing actions,
               noting my role's limits from get-my-permissions.{$focus}
            TEXT);
    }

    /**
     * @return array<int, Argument>
     */
    public function arguments(): array
    {
        return [
            new Argument('account_id', 'Trading account ID to review (defaults to your first accessible account).'),
            new Argument('focus', 'Optional area to focus on, e.g. "tech exposure" or "dividend income".'),
        ];
    }
}
