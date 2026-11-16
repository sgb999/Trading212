<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AccountOverview;
use App\Filament\Widgets\OpenPositions;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Log;
use UnitEnum;

class Dashboard extends BaseDashboard
{
    protected static string|UnitEnum|null $navigationGroup = 'Trading';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    public function getWidgets(): array
    {
        return [
            AccountOverview::class,
            OpenPositions::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    /**
     * The kill switch state, memoised for the current request (private, so Livewire does not persist it).
     */
    private ?bool $isAgentTradingHalted = null;

    public function getSubheading(): ?string
    {
        return $this->isAgentTradingHalted()
            ? 'AI agent trading is HALTED: every agent order and cancellation is being denied.'
            : 'AI agent trading is enabled.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('haltAgentTrading')
                ->label('Halt AI trading')
                ->icon(Heroicon::OutlinedStopCircle)
                ->color('danger')
                ->visible(fn (): bool => ! $this->isAgentTradingHalted())
                ->requiresConfirmation()
                ->modalHeading('Halt all AI agent trading?')
                ->modalDescription(
                    'Every AI agent order and cancellation will be denied until you resume. '
                    .'Orders and cancellations you place yourself are not affected.'
                )
                ->modalSubmitActionLabel('Yes, halt AI trading')
                ->action(fn () => $this->setAgentTradingHalted(true)),
            Action::make('resumeAgentTrading')
                ->label('Resume AI trading')
                ->icon(Heroicon::OutlinedPlay)
                ->color('success')
                ->visible(fn (): bool => $this->isAgentTradingHalted())
                ->requiresConfirmation()
                ->modalHeading('Resume AI agent trading?')
                ->modalDescription('AI agents will be able to place and cancel orders again, within their role limits.')
                ->modalSubmitActionLabel('Yes, resume')
                ->action(fn () => $this->setAgentTradingHalted(false)),
            Action::make('placeOrder')
                ->label('Place order')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(Trade::getUrl()),
        ];
    }

    private function isAgentTradingHalted(): bool
    {
        return $this->isAgentTradingHalted ??= Setting::isAgentTradingHalted();
    }

    /**
     * Set the kill switch to an explicit state (idempotent, so a stale confirmation cannot flip it back).
     */
    private function setAgentTradingHalted(bool $halted): void
    {
        Setting::setAgentTradingHalted($halted);
        $this->isAgentTradingHalted = $halted;

        $user = auth()->user();
        Log::warning($halted ? 'AI agent trading halted from the admin panel.' : 'AI agent trading resumed from the admin panel.', [
            'user_id' => $user?->getAuthIdentifier(),
            'user_email' => $user?->email,
            'agent_trading_halted' => $halted,
        ]);

        Notification::make()
            ->status($halted ? 'warning' : 'success')
            ->title($halted ? 'AI agent trading halted' : 'AI agent trading resumed')
            ->send();
    }
}
