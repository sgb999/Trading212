<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\TimeValidity;
use App\Enums\TradeOrderStatus;
use App\Traits\BelongsToTradingAccountTrait;
use Database\Factories\TradeOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;

/**
 * Local audit record of an order submitted through this platform (by a user or AI agent).
 */
#[Fillable([
    'trading_account_id',
    'external_id',
    'ticker',
    'type',
    'side',
    'quantity',
    'limit_price',
    'stop_price',
    'time_validity',
    'extended_hours',
    'status',
    'external_status',
    'placed_by_type',
    'placed_by_id',
    'response',
    'error',
])]
class TradeOrder extends Model
{
    use BelongsToTradingAccountTrait;

    /** @use HasFactory<TradeOrderFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OrderType::class,
            'side' => OrderSide::class,
            'time_validity' => TimeValidity::class,
            'status' => TradeOrderStatus::class,
            'quantity' => 'decimal:8',
            'limit_price' => 'decimal:8',
            'stop_price' => 'decimal:8',
            'extended_hours' => 'boolean',
            'response' => 'array',
        ];
    }

    /**
     * The User or AiAgent who placed the order.
     *
     * @return MorphTo<Model, $this>
     */
    public function placedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Applies the attributes only if the stored row still has one of the given statuses, checked
     * under a row lock, so a late writer (PlaceOrder after a slow request, or a sync working from
     * a stale read) never overwrites an outcome another process already recorded. This instance is
     * refreshed from the database either way.
     *
     * @param  list<TradeOrderStatus>  $statuses
     * @param  array<string, mixed>  $attributes
     * @return bool Whether the attributes were applied
     */
    public function updateIfStatusIn(array $statuses, array $attributes): bool
    {
        $wasApplied = DB::transaction(function () use ($statuses, $attributes): bool {
            $current = static::query()->whereKey($this->getKey())->lockForUpdate()->first();

            if ($current === null || ! in_array($current->status, $statuses, true)) {
                return false;
            }

            return $current->update($attributes);
        });

        $this->refresh();

        return $wasApplied;
    }
}
