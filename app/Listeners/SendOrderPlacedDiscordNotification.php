<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Models\AiAgent;
use App\Models\TradeOrder;
use App\Models\User;
use App\Services\Discord\DiscordWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Posts every placed order (ticker, side, quantity, price, status and who placed it) to Discord.
 */
class SendOrderPlacedDiscordNotification implements ShouldQueue
{
    /**
     * Discord delivery is best effort; a failed post is logged, never retried.
     */
    public int $tries = 1;

    public function __construct(private readonly DiscordWebhook $discord) {}

    /**
     * Skips queueing entirely when no webhook is configured.
     */
    public function shouldQueue(OrderPlaced $event): bool
    {
        return $this->discord->isConfigured();
    }

    public function handle(OrderPlaced $event): void
    {
        $order = $event->tradeOrder->loadMissing('placedBy');

        $this->discord->send([
            'embeds' => [[
                'title' => sprintf('Order placed: %s %s', $order->side->value, $order->ticker),
                'fields' => [
                    ['name' => 'Ticker', 'value' => $order->ticker, 'inline' => true],
                    ['name' => 'Side', 'value' => $order->side->value, 'inline' => true],
                    ['name' => 'Quantity', 'value' => $this->formatNumber($order->quantity), 'inline' => true],
                    ['name' => 'Price', 'value' => $this->price($order), 'inline' => true],
                    ['name' => 'Status', 'value' => $order->status->value, 'inline' => true],
                    ['name' => 'Placed by', 'value' => $this->placedBy($order), 'inline' => true],
                ],
            ]],
        ]);
    }

    /**
     * Limit and/or stop price, or "Market" for market orders.
     */
    private function price(TradeOrder $order): string
    {
        $prices = array_filter([
            'Limit' => $order->limit_price,
            'Stop' => $order->stop_price,
        ], fn (?string $price): bool => $price !== null);

        if ($prices === []) {
            return 'Market';
        }

        return collect($prices)
            ->map(fn (string $price, string $label): string => "{$label} {$this->formatNumber($price)}")
            ->implode(', ');
    }

    private function placedBy(TradeOrder $order): string
    {
        return match (true) {
            $order->placedBy instanceof AiAgent => "Agent: {$order->placedBy->name}",
            $order->placedBy instanceof User => "User: {$order->placedBy->name}",
            default => 'Unknown',
        };
    }

    /**
     * Strips the trailing zeros of an 8-decimal cast value (e.g. "1.50000000" becomes "1.5").
     */
    private function formatNumber(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
