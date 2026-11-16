<?php

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\AiAgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Str;

/**
 * An AI agent that talks to the platform through the MCP server using a bearer token.
 */
#[Fillable(['user_id', 'role_id', 'name', 'description', 'is_active', 'last_used_at'])]
#[Hidden(['token_hash'])]
class AiAgent extends Model
{
    /** @use HasFactory<AiAgentFactory> */
    use HasFactory;

    public const string TOKEN_PREFIX = 't212a_';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Trading accounts the agent is allowed to operate on.
     *
     * @return BelongsToMany<TradingAccount, $this>
     */
    public function tradingAccounts(): BelongsToMany
    {
        return $this->belongsToMany(TradingAccount::class);
    }

    /**
     * @return HasMany<AgentActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(AgentActivityLog::class);
    }

    /**
     * @return MorphMany<TradeOrder, $this>
     */
    public function tradeOrders(): MorphMany
    {
        return $this->morphMany(TradeOrder::class, 'placed_by');
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->is_active && $this->role?->hasPermission($permission) === true;
    }

    /**
     * Whether the agent may act on the given account (assigned + live permission when live).
     */
    public function canAccessAccount(TradingAccount $account): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($account->isLive() && ! $this->hasPermission(Permission::TradeLive)) {
            return false;
        }

        return $this->tradingAccounts()->whereKey($account->getKey())->exists();
    }

    /**
     * Generate a fresh plain-text token, store its hash, and return it (shown once).
     */
    public function issueToken(): string
    {
        $plain = self::TOKEN_PREFIX.Str::random(48);

        $this->forceFill([
            'token_hash' => hash('sha256', $plain),
            'token_prefix' => substr($plain, 0, 12),
        ])->save();

        return $plain;
    }

    public static function findByToken(string $plainToken): ?self
    {
        return static::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->where('is_active', true)
            ->first();
    }
}
