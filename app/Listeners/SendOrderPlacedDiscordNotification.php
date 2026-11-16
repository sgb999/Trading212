<?php

namespace App\Listeners;

use App\Events\OrderPlaced;
use App\Models\AiAgent;
use App\Models\TradeOrder;
use App\Models\User;
use App\Services\Discord\DiscordWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts every placed order (ticker, side, quantity, price, status and who placed it) to Discord.
 */
class SendOrderPlacedDiscordNotification implements ShouldQueue
{
    /**
     * Discord delivery is best effort; a failed post is logged, never retried.
     */
    public int $tries = 1;

    /**
     * Discord rejects embeds with an empty field value.
     */
    private const string EMPTY_FIELD_VALUE = '—';

    public function __construct(private readonly DiscordWebhook $discord) {}

    /**
     * Skips queueing entirely when no webhook is configured.
     */
    public function shouldQueue(OrderPlaced $event): bool
    {
        return $this->discord->isConfigured();
    }

    /**
     * Never throws: under the `sync` queue this runs inside OrderPlaced::dispatch, and an
     * exception there would look like a failed placement and invite a duplicate retry.
     */
    public function handle(OrderPlaced $event): void
    {
        try {
            $this->discord->send($this->payload($event->tradeOrder->loadMissing('placedBy')));
        } catch (Throwable $e) {
            Log::warning('Discord order notification could not be built or sent.', [
                'trade_order_id' => $event->tradeOrder->getKey(),
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * @return array{embeds: list<array{title: string, fields: list<array{name: string, value: string, inline: bool}>}>}
     */
    private function payload(TradeOrder $order): array
    {
        $fields = [
            'Ticker' => $order->ticker,
            'Side' => $order->side->value,
            'Quantity' => $this->formatNumber($order->quantity),
            'Price' => $this->price($order),
            'Status' => $order->status->value,
            'Placed by' => $this->placedBy($order),
        ];

        return [
            'embeds' => [[
                'title' => DiscordWebhook::escapeMarkdown(sprintf('Order placed: %s %s', $order->side->value, $order->ticker)),
                'fields' => collect($fields)
                    ->map(fn (?string $value, string $name): array => [
                        'name' => $name,
                        'value' => filled($value) ? DiscordWebhook::escapeMarkdown($value) : self::EMPTY_FIELD_VALUE,
                        'inline' => true,
                    ])
                    ->values()
                    ->all(),
            ]],
        ];
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
