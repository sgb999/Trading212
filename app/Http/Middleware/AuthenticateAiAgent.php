<?php

namespace App\Http\Middleware;

use App\Mcp\CurrentAgent;
use App\Models\AiAgent;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an AI agent from an `Authorization: Bearer <token>` header for the MCP endpoint.
 *
 * On success the agent is bound to {@see CurrentAgent} and `last_used_at` is refreshed at most
 * once per minute. On failure a 401 JSON-RPC error is returned.
 */
class AuthenticateAiAgent
{
    /**
     * JSON-RPC server-defined error code used for authentication failures.
     */
    public const int UNAUTHORIZED_CODE = -32001;

    private const int LAST_USED_THROTTLE_SECONDS = 60;

    public function __construct(private readonly CurrentAgent $currentAgent) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (blank($token)) {
            return $this->unauthorized($request, 'Missing bearer token. Send "Authorization: Bearer <agent token>".');
        }

        $agent = AiAgent::findByToken($token);

        if ($agent === null) {
            return $this->unauthorized($request, 'Invalid or revoked agent token, or the agent is inactive.');
        }

        $this->touchLastUsed($agent);

        $this->currentAgent->set($agent);

        return $next($request);
    }

    private function touchLastUsed(AiAgent $agent): void
    {
        if (
            $agent->last_used_at !== null
            && $agent->last_used_at->gt(now()->subSeconds(self::LAST_USED_THROTTLE_SECONDS))
        ) {
            return;
        }

        AiAgent::withoutTimestamps(fn () => $agent->forceFill(['last_used_at' => now()])->saveQuietly());
    }

    private function unauthorized(Request $request, string $message): JsonResponse
    {
        $id = $request->json('id');

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => is_int($id) || is_string($id) ? $id : null,
            'error' => [
                'code' => self::UNAUTHORIZED_CODE,
                'message' => "Unauthorized: {$message}",
            ],
        ], 401);
    }
}
