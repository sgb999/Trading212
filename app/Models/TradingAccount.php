<?php

namespace App\Models;

use App\Actions\RecordPortfolioSnapshot;
use App\Enums\Trading212Environment;
use App\Services\Trading212\Trading212Client;
use Database\Factories\TradingAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Trading 212 account (demo or live) connected via API key + secret.
 */
#[Fillable([
    'user_id',
    'name',
    'environment',
    'api_key',
    'api_secret',
    'external_account_id',
    'currency',
    'is_default',
    'last_synced_at',
])]
#[Hidden(['api_key', 'api_secret'])]
class TradingAccount extends Model
{
    /** @use HasFactory<TradingAccountFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (TradingAccount $account): void {
            if ($account->isDirty(['api_key', 'environment']) || blank($account->credential_fingerprint)) {
                $account->credential_fingerprint = hash('sha256', $account->environment->value.'|'.$account->api_key);
            }
        });

        static::saved(function (TradingAccount $account): void {
            if ($account->is_default) {
                static::query()
                    ->where('user_id', $account->user_id)
                    ->whereKeyNot($account->getKey())
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => Trading212Environment::class,
            'api_key' => 'encrypted',
            'api_secret' => 'encrypted',
            'is_default' => 'boolean',
            'last_synced_at' => 'datetime',
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
     * @return HasMany<TradeOrder, $this>
     */
    public function tradeOrders(): HasMany
    {
        return $this->hasMany(TradeOrder::class);
    }

    /**
     * @return HasMany<PortfolioSnapshot, $this>
     */
    public function portfolioSnapshots(): HasMany
    {
        return $this->hasMany(PortfolioSnapshot::class);
    }

    /**
     * The stored baseline (first snapshot). Set once by {@see RecordPortfolioSnapshot} and deliberately not
     * fillable; it is only replaced when the credentials switch to another Trading 212 account.
     * Guards should use {@see self::baseline()}, which also checks the snapshot belongs to the current one.
     *
     * @return BelongsTo<PortfolioSnapshot, $this>
     */
    public function baselineSnapshot(): BelongsTo
    {
        return $this->belongsTo(PortfolioSnapshot::class, 'baseline_snapshot_id');
    }

    /**
     * The baseline of the Trading 212 account the credentials currently point at, or null when there is
     * none yet or it was taken from a different Trading 212 account (summary `id`).
     */
    public function baseline(): ?PortfolioSnapshot
    {
        $baseline = $this->baselineSnapshot;

        if ($baseline === null || $this->external_account_id === null) {
            return null;
        }

        return (int) $baseline->external_account_id === (int) $this->external_account_id ? $baseline : null;
    }

    /**
     * The first snapshot taken today (Europe/London calendar day) of the current Trading 212 account, if any.
     */
    public function firstSnapshotToday(): ?PortfolioSnapshot
    {
        return $this->portfolioSnapshots()
            ->where('external_account_id', $this->external_account_id ?? 0)
            ->takenOnLondonDay()
            ->oldest('taken_at')
            ->oldest('id')
            ->first();
    }

    /**
     * @return BelongsToMany<AiAgent, $this>
     */
    public function aiAgents(): BelongsToMany
    {
        return $this->belongsToMany(AiAgent::class);
    }

    public function isLive(): bool
    {
        return $this->environment === Trading212Environment::Live;
    }

    /**
     * A rate-limited, cached Trading 212 API client for this account.
     */
    public function client(): Trading212Client
    {
        return Trading212Client::for($this);
    }

    /**
     * Private broadcast channel name for realtime updates of this account.
     */
    public function broadcastChannel(): string
    {
        return "trading-accounts.{$this->getKey()}";
    }
}
