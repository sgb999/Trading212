<?php

namespace App\Models;

use App\Traits\BelongsToTradingAccountTrait;
use Database\Factories\AgentActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of every MCP tool call an AI agent makes. `outcome` is one of: allowed, denied, error.
 */
#[Fillable(['ai_agent_id', 'trading_account_id', 'tool', 'arguments', 'outcome', 'message'])]
class AgentActivityLog extends Model
{
    use BelongsToTradingAccountTrait;

    /** @use HasFactory<AgentActivityLogFactory> */
    use HasFactory;

    public const string OUTCOME_ALLOWED = 'allowed';

    public const string OUTCOME_DENIED = 'denied';

    public const string OUTCOME_ERROR = 'error';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
        ];
    }

    /**
     * @return BelongsTo<AiAgent, $this>
     */
    public function aiAgent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class);
    }
}
