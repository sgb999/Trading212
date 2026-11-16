<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\TimeValidity;
use App\Enums\TradeOrderStatus;
use Database\Factories\TradeOrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
     * @return BelongsTo<TradingAccount, $this>
     */
    public function tradingAccount(): BelongsTo
    {
        return $this->belongsTo(TradingAccount::class);
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
}
