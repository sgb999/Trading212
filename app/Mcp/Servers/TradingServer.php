<?php

namespace App\Mcp\Servers;

use App\Mcp\Prompts\PortfolioReviewPrompt;
use App\Mcp\Resources\PermissionsResource;
use App\Mcp\Tools\CancelOrderTool;
use App\Mcp\Tools\GetAccountSummaryTool;
use App\Mcp\Tools\GetDividendsTool;
use App\Mcp\Tools\GetMyPermissionsTool;
use App\Mcp\Tools\GetOrderHistoryTool;
use App\Mcp\Tools\GetOrderTool;
use App\Mcp\Tools\GetPortfolioTool;
use App\Mcp\Tools\GetTransactionsTool;
use App\Mcp\Tools\ListAccountsTool;
use App\Mcp\Tools\ListPendingOrdersTool;
use App\Mcp\Tools\PlaceOrderTool;
use App\Mcp\Tools\SearchInstrumentsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Trading Platform')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    This server lets you read and trade the owner's Trading 212 stock brokerage accounts.

    Accounts
    - Each account is either DEMO (paper money, safe to experiment) or LIVE (REAL money).
      Call `list-accounts` first; it shows each account's id and environment.
    - Every account tool accepts an optional `account_id`; when omitted your first accessible account is used.
      Be explicit when acting on a live account.

    Permissions
    - You act under a role that grants specific permissions and limits
      (max quantity / value per order, orders per day, allowed tickers).
      Tools you are not allowed to use are not listed. Call `get-my-permissions` to self-check before trading.
    - Every call is audited. Denied requests are logged, so do not retry a denied action with different wording —
      tell the user instead.

    Trading
    - Find the exact Trading 212 ticker (e.g. `AAPL_US_EQ`) with `search-instruments` before ordering.
    - `place-order` always takes a POSITIVE `quantity`.
      To sell, use `side=SELL` (it is sent to Trading 212 as a negative quantity). You can only sell shares you hold.
    - Order types: MARKET, LIMIT (needs limit_price), STOP (needs stop_price), STOP_LIMIT (needs both).
    - Check `list-pending-orders` afterwards; cancel with `cancel-order`.

    Rate limits
    - Trading 212 is strictly rate limited. Responses are cached briefly.
      If a tool reports a rate limit, wait the stated number of seconds before retrying.
    MARKDOWN)]
class TradingServer extends Server
{
    public int $defaultPaginationLength = 50;

    protected array $tools = [
        ListAccountsTool::class,
        GetMyPermissionsTool::class,
        GetAccountSummaryTool::class,
        GetPortfolioTool::class,
        ListPendingOrdersTool::class,
        GetOrderTool::class,
        SearchInstrumentsTool::class,
        GetOrderHistoryTool::class,
        GetDividendsTool::class,
        GetTransactionsTool::class,
        PlaceOrderTool::class,
        CancelOrderTool::class,
    ];

    protected array $resources = [
        PermissionsResource::class,
    ];

    protected array $prompts = [
        PortfolioReviewPrompt::class,
    ];
}
