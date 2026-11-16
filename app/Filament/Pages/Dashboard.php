<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AccountOverview;
use App\Filament\Widgets\OpenPositions;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;
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

    public function getSubheading(): ?string
    {
        return Setting::isAgentTradingHalted()
            ? 'AI agent trading is HALTED: every agent order is being denied.'
            : 'AI agent trading is enabled.';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->agentKillSwitchAction(),
            Action::make('placeOrder')
                ->label('Place order')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(Trade::getUrl()),
        ];
    }

    /**
     * Global kill switch for AI agent trading. Human orders are not affected.
     */
    private function agentKillSwitchAction(): Action
    {
        $isHalted = fn (): bool => Setting::isAgentTradingHalted();

        return Action::make('toggleAgentTrading')
            ->label(fn (): string => $isHalted() ? 'Resume AI trading' : 'Halt AI trading')
            ->icon(fn (): Heroicon => $isHalted() ? Heroicon::OutlinedPlay : Heroicon::OutlinedStopCircle)
            ->color(fn (): string => $isHalted() ? 'success' : 'danger')
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $isHalted() ? 'Resume AI agent trading?' : 'Halt all AI agent trading?')
            ->modalDescription(fn (): string => $isHalted()
                ? 'AI agents will be able to place orders again, within their role limits.'
                : 'Every AI agent order will be denied until you resume. Your own orders and order cancellations are not affected.')
            ->modalSubmitActionLabel(fn (): string => $isHalted() ? 'Yes, resume' : 'Yes, halt AI trading')
            ->action(function () use ($isHalted): void {
                $halt = ! $isHalted();
                Setting::setAgentTradingHalted($halt);

                Notification::make()
                    ->status($halt ? 'warning' : 'success')
                    ->title($halt ? 'AI agent trading halted' : 'AI agent trading resumed')
                    ->send();
            });
    }
}
