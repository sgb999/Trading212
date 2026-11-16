<?php

namespace App\Events;

use App\Models\TradingAccount;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Broadcast on `private-trading-accounts.{id}` as `portfolio.updated` whenever positions,
 * orders or the account summary may have changed. Clients should simply re-fetch.
 */
class PortfolioUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public TradingAccount $tradingAccount) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel($this->tradingAccount->broadcastChannel())];
    }

    public function broadcastAs(): string
    {
        return 'portfolio.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['trading_account_id' => $this->tradingAccount->id, 'at' => now()->toIso8601String()];
    }
}
