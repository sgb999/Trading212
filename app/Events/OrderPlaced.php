<?php

namespace App\Events;

use App\Models\TradeOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on `private-trading-accounts.{id}` as `order.placed`.
 */
class OrderPlaced implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TradeOrder $tradeOrder) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("trading-accounts.{$this->tradeOrder->trading_account_id}")];
    }

    public function broadcastAs(): string
    {
        return 'order.placed';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->tradeOrder->id,
            'ticker' => $this->tradeOrder->ticker,
            'type' => $this->tradeOrder->type->value,
            'side' => $this->tradeOrder->side->value,
            'quantity' => (float) $this->tradeOrder->quantity,
            'status' => $this->tradeOrder->status->value,
            'placed_by' => class_basename((string) $this->tradeOrder->placed_by_type),
        ];
    }
}
