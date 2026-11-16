<?php

namespace App\Mcp\Tools;

use App\Enums\Permission;
use App\Exceptions\AgentNotAuthorizedException;
use App\Mcp\CurrentAgent;
use App\Models\AgentActivityLog;
use App\Models\AiAgent;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use Throwable;
use ValueError;

/**
 * Base class for every Trading Platform MCP tool.
 *
 * It hides the tool from agents whose role lacks the required permission, re-checks that
 * permission on every call, resolves the target trading account, converts domain errors into
 * MCP tool errors and writes one {@see AgentActivityLog} entry per call.
 */
abstract class TradingTool extends Tool
{
    /**
     * Whether the tool operates on a trading account (and accepts `account_id`).
     */
    protected bool $usesAccount = true;

    /**
     * Run the tool for an authorized agent.
     */
    abstract protected function execute(
        Request $request,
        AiAgent $agent,
        ?TradingAccount $account,
    ): Response|ResponseFactory;

    /**
     * The permissions of which the agent needs at least one. Empty = available to every agent.
     *
     * @return list<Permission>
     */
    protected function requiredPermissions(): array
    {
        return [];
    }

    /**
     * Tool-specific input schema (merged with `account_id` for account tools).
     * Return an empty array when the tool takes no inputs of its own.
     *
     * @return array<string, Type>
     */
    abstract protected function toolSchema(JsonSchema $schema): array;

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        $accountSchema = $this->usesAccount
            ? ['account_id' => $schema->integer()->description(
                'Trading account ID from list-accounts. Defaults to your first accessible account.'
            )]
            : [];

        return [...$accountSchema, ...$this->toolSchema($schema)];
    }

    public function shouldRegister(CurrentAgent $currentAgent): bool
    {
        $agent = $currentAgent->get();

        return $agent !== null && $this->isPermitted($agent);
    }

    public function handle(Request $request, CurrentAgent $currentAgent): Response|ResponseFactory
    {
        $agent = $currentAgent->get();

        if ($agent === null) {
            return Response::error('Not authenticated: no AI agent is bound to this MCP session.');
        }

        $account = null;

        try {
            if (! $this->isPermitted($agent)) {
                throw new AgentNotAuthorizedException($this->missingPermissionMessage($agent));
            }

            if ($this->usesAccount) {
                $account = $this->resolveAccount($agent, $request->get('account_id'));
            }

            $response = $this->execute($request, $agent, $account);
        } catch (AgentNotAuthorizedException $e) {
            return $this->fail(
                $agent,
                $request,
                $account,
                AgentActivityLog::OUTCOME_DENIED,
                "Permission denied: {$e->getMessage()}",
            );
        } catch (ValidationException $e) {
            return $this->fail(
                $agent,
                $request,
                $account,
                AgentActivityLog::OUTCOME_ERROR,
                ValidationMessages::from($e),
            );
        } catch (InvalidArgumentException|ValueError $e) {
            return $this->fail(
                $agent,
                $request,
                $account,
                AgentActivityLog::OUTCOME_ERROR,
                "Invalid input: {$e->getMessage()}",
            );
        } catch (RateLimitExceededException $e) {
            $message = "Trading 212 rate limit reached for [{$e->endpoint}]. Retry after {$e->retryAfter} seconds.";
            $this->log($agent, $request, $account, AgentActivityLog::OUTCOME_ERROR, $message);

            return Response::make([Response::error($message)])
                ->withStructuredContent([
                    'error' => 'rate_limited',
                    'endpoint' => $e->endpoint,
                    'retry_after_seconds' => $e->retryAfter,
                ]);
        } catch (Trading212Exception $e) {
            return $this->fail(
                $agent,
                $request,
                $account,
                AgentActivityLog::OUTCOME_ERROR,
                "Trading 212 API error: {$e->getMessage()}",
            );
        } catch (Throwable $e) {
            $this->log(
                $agent,
                $request,
                $account,
                AgentActivityLog::OUTCOME_ERROR,
                'Unexpected error: '.class_basename($e),
            );

            throw $e;
        }

        $this->log($agent, $request, $account, AgentActivityLog::OUTCOME_ALLOWED, null);

        return $response;
    }

    protected function isPermitted(AiAgent $agent): bool
    {
        $permissions = $this->requiredPermissions();

        if (! $agent->is_active) {
            return false;
        }

        return $permissions === []
            || collect($permissions)->contains(fn (Permission $permission): bool => $agent->hasPermission($permission));
    }

    /**
     * Resolve the requested account, or the agent's first accessible account.
     *
     * @throws AgentNotAuthorizedException
     */
    protected function resolveAccount(AiAgent $agent, mixed $accountId): TradingAccount
    {
        if ($accountId !== null && $accountId !== '') {
            $account = filter_var($accountId, FILTER_VALIDATE_INT) !== false
                ? TradingAccount::query()->find((int) $accountId)
                : null;

            if ($account === null || ! $agent->canAccessAccount($account)) {
                throw new AgentNotAuthorizedException(
                    "Trading account [{$accountId}] does not exist or is not accessible to this agent. "
                    .'Use list-accounts to see your accounts.'
                );
            }

            return $account;
        }

        $accounts = $agent->tradingAccounts()->orderByDesc('is_default')->orderBy('trading_accounts.id')->get();

        if ($accounts->isEmpty()) {
            throw new AgentNotAuthorizedException('No trading account is assigned to this agent.');
        }

        return $accounts->first(fn (TradingAccount $account): bool => $agent->canAccessAccount($account))
            ?? throw new AgentNotAuthorizedException(
                'None of the trading accounts assigned to this agent are accessible '
                .'(live accounts require the TradeLive permission).'
            );
    }

    /**
     * Safe, non-secret description of an account to include in tool output.
     *
     * @return array{id: int, name: string, environment: string, currency: string|null}
     */
    protected function describeAccount(TradingAccount $account): array
    {
        return [
            'id' => $account->getKey(),
            'name' => $account->name,
            'environment' => $account->environment->value,
            'currency' => $account->currency,
        ];
    }

    /**
     * Normalise a Trading 212 history page into items + the cursor for the next page.
     *
     * @param  array{items: list<array<string, mixed>>, nextPagePath: string|null}  $page
     * @return array{items: list<array<string, mixed>>, next_cursor: string|null}
     */
    protected function historyPage(array $page): array
    {
        parse_str((string) parse_url((string) $page['nextPagePath'], PHP_URL_QUERY), $query);

        return [
            'items' => $page['items'],
            'next_cursor' => isset($query['cursor']) && is_string($query['cursor']) ? $query['cursor'] : null,
        ];
    }

    private function missingPermissionMessage(AiAgent $agent): string
    {
        $required = collect($this->requiredPermissions())
            ->map(fn (Permission $permission): string => $permission->value)
            ->implode(' or ');

        return "Role [{$agent->role?->name}] lacks the [{$required}] permission required by [{$this->name()}].";
    }

    private function fail(
        AiAgent $agent,
        Request $request,
        ?TradingAccount $account,
        string $outcome,
        string $message,
    ): Response {
        $this->log($agent, $request, $account, $outcome, $message);

        return Response::error($message);
    }

    private function log(
        AiAgent $agent,
        Request $request,
        ?TradingAccount $account,
        string $outcome,
        ?string $message,
    ): void {
        $agent->activityLogs()->create([
            'trading_account_id' => $account?->getKey() ?? $this->attemptedAccountId($request),
            'tool' => $this->name(),
            'arguments' => $request->all(),
            'outcome' => $outcome,
            'message' => $message,
        ]);
    }

    /**
     * The existing account an agent attempted to use, so denied cross-account attempts are traceable.
     */
    private function attemptedAccountId(Request $request): ?int
    {
        $accountId = $request->get('account_id');

        if (! $this->usesAccount || filter_var($accountId, FILTER_VALIDATE_INT) === false) {
            return null;
        }

        return TradingAccount::query()->whereKey((int) $accountId)->value('id');
    }
}
