<?php

namespace App\Filament\Resources\TradingAccounts\Actions;

use App\Models\TradingAccount;
use App\Services\Trading212\Exceptions\RateLimitExceededException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Number;

/**
 * Calls the account summary endpoint to verify the stored API credentials.
 */
class TestConnectionAction
{
    public static function make(): Action
    {
        return Action::make('testConnection')
            ->label('Test connection')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->action(function (TradingAccount $record): void {
                try {
                    $summary = $record->client()->accountSummary(fresh: true);
                } catch (RateLimitExceededException $e) {
                    Notification::make()
                        ->warning()
                        ->title('Rate limit reached')
                        ->body(
                            'Trading 212 only allows one account check every few seconds. '
                            ."Try again in {$e->retryAfter}s."
                        )
                        ->send();

                    return;
                } catch (Trading212Exception $e) {
                    Notification::make()
                        ->danger()
                        ->title('Connection failed')
                        ->body($e->getMessage())
                        ->persistent()
                        ->send();

                    return;
                }

                $record->forceFill(array_filter([
                    'external_account_id' => $summary['id'] ?? null,
                    'currency' => $summary['currency'] ?? null,
                ]))->save();

                Notification::make()
                    ->success()
                    ->title('Connected to Trading 212')
                    ->body(sprintf(
                        'Account #%s (%s) — total value %s.',
                        $summary['id'] ?? '?',
                        $record->environment->getLabel(),
                        Number::currency((float) ($summary['totalValue'] ?? 0), in: $summary['currency'] ?? 'GBP'),
                    ))
                    ->send();
            });
    }
}
