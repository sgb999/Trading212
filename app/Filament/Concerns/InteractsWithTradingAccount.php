<?php

namespace App\Filament\Concerns;

use App\Filament\Support\ActiveTradingAccount;
use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Trading212Client;
use Closure;
use Filament\Notifications\Notification;

/**
 * Shared helpers for pages / widgets that work on the user's active trading account:
 * resolving the account, safely calling the Trading 212 API and refreshing on realtime events.
 */
trait InteractsWithTradingAccount
{
    protected ?TradingAccount $resolvedTradingAccount = null;

    /**
     * The last read error from Trading 212 during this request (shown in empty states / stats).
     */
    protected ?string $tradingApiError = null;

    protected function tradingAccount(): ?TradingAccount
    {
        return $this->resolvedTradingAccount ??= ActiveTradingAccount::get();
    }

    /**
     * Listen for realtime updates on the active account's private channel (when Echo is configured).
     *
     * @return array<string, string>
     */
    public function getListeners(): array
    {
        $listeners = parent::getListeners();
        $account = $this->tradingAccount();

        if ($account === null || blank(config('filament.broadcasting.echo'))) {
            return $listeners;
        }

        $channel = "echo-private:{$account->broadcastChannel()}";

        return [
            ...$listeners,
            "{$channel},.portfolio.updated" => '$refresh',
            "{$channel},.order.placed" => '$refresh',
        ];
    }

    /**
     * Run a read against the Trading 212 API, capturing failures instead of throwing.
     *
     * @template TResult
     *
     * @param  Closure(Trading212Client): TResult  $callback
     * @param  TResult  $default
     * @return TResult
     */
    protected function fetchFromTrading212(Closure $callback, mixed $default = null): mixed
    {
        $account = $this->tradingAccount();

        if ($account === null) {
            $this->tradingApiError = 'No trading account connected yet. Add one under Settings → Trading accounts.';

            return $default;
        }

        try {
            return $callback($account->client());
        } catch (RateLimitExceededException $e) {
            $this->tradingApiError = "Trading 212 rate limit reached — try again in {$e->retryAfter}s.";
        } catch (Trading212Exception $e) {
            $this->tradingApiError = $e->getMessage();
        }

        return $default;
    }

    protected function notifyTrading212Failure(
        Trading212Exception $exception,
        string $title = 'Trading 212 request failed',
    ): void {
        if ($exception instanceof RateLimitExceededException) {
            Notification::make()
                ->warning()
                ->title('Slow down — rate limit reached')
                ->body("Trading 212 only allows a few requests per minute. Try again in {$exception->retryAfter}s.")
                ->send();

            return;
        }

        Notification::make()
            ->danger()
            ->title($title)
            ->body($exception->getMessage())
            ->persistent()
            ->send();
    }
}
