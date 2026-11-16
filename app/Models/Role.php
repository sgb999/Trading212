<?php

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A set of permissions and trading limits that can be assigned to AI agents.
 *
 * @property list<string> $permissions
 * @property list<string>|null $allowed_tickers
 * @property bool $restrict_to_allowed_tickers When true, an empty allowed_tickers list denies every ticker.
 */
#[Fillable([
    'name',
    'slug',
    'description',
    'permissions',
    'max_order_quantity',
    'max_order_value',
    'max_orders_per_day',
    'max_daily_loss_percent',
    'min_portfolio_value',
    'allowed_tickers',
    'restrict_to_allowed_tickers',
])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'permissions' => '[]',
        'restrict_to_allowed_tickers' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'allowed_tickers' => 'array',
            'restrict_to_allowed_tickers' => 'boolean',
            'max_order_quantity' => 'decimal:8',
            'max_order_value' => 'decimal:2',
            'max_orders_per_day' => 'integer',
            'max_daily_loss_percent' => 'decimal:2',
            'min_portfolio_value' => 'decimal:2',
        ];
    }

    /**
     * @return HasMany<AiAgent, $this>
     */
    public function aiAgents(): HasMany
    {
        return $this->hasMany(AiAgent::class);
    }

    public function hasPermission(Permission $permission): bool
    {
        return in_array($permission->value, $this->permissions ?? [], true);
    }

    /**
     * An empty allowed_tickers list allows any ticker, unless the role restricts trading to the list:
     * then an empty list denies every ticker (fail closed until the owner fills it in).
     */
    public function allowsTicker(string $ticker): bool
    {
        if (blank($this->allowed_tickers)) {
            return ! $this->restrict_to_allowed_tickers;
        }

        return in_array(strtoupper($ticker), array_map('strtoupper', $this->allowed_tickers), true);
    }
}
