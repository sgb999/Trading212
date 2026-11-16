<?php

namespace App\Filament\Pages;

use App\Actions\CancelOrder;
use App\Enums\OrderSide;
use App\Filament\Concerns\InteractsWithTradingAccount;
use App\Services\Trading212\Exceptions\Trading212Exception;
use App\Services\Trading212\Trading212Client;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Working (unfilled) orders on the active account, with cancellation.
 */
class PendingOrders extends Page implements HasTable
{
    use InteractsWithTable;
    use InteractsWithTradingAccount;

    protected static string|UnitEnum|null $navigationGroup = 'Trading';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Orders';

    protected static ?string $title = 'Pending orders';

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => collect($this->fetchFromTrading212(
                fn (Trading212Client $client): array => $client->pendingOrders(),
                [],
            ))
                ->map(fn (array $order): array => [
                    'id' => (int) $order['id'],
                    'ticker' => (string) ($order['ticker'] ?? $order['instrument']['ticker'] ?? ''),
                    'name' => $order['instrument']['name'] ?? null,
                    'currency' => $order['currency'] ?? $order['instrument']['currency'] ?? null,
                    'type' => $order['type'] ?? null,
                    'side' => $order['side'] ?? ((float) ($order['quantity'] ?? 0) < 0 ? 'SELL' : 'BUY'),
                    'status' => $order['status'] ?? null,
                    'quantity' => abs((float) ($order['quantity'] ?? 0)),
                    'filled_quantity' => abs((float) ($order['filledQuantity'] ?? 0)),
                    'limit_price' => $order['limitPrice'] ?? null,
                    'stop_price' => $order['stopPrice'] ?? null,
                    'time_validity' => $order['timeInForce'] ?? $order['timeValidity'] ?? null,
                    'created_at' => isset($order['createdAt']) ? Carbon::parse($order['createdAt']) : null,
                ])
                ->sortByDesc('created_at')
                ->keyBy('id')
                ->all())
            ->columns([
                TextColumn::make('created_at')->label('Created')->since()->dateTimeTooltip(),
                TextColumn::make('ticker')->label('Instrument')->weight('medium')
                    ->description(fn (array $record): ?string => $record['name']),
                TextColumn::make('side')->badge()
                    ->formatStateUsing(
                        fn (?string $state): string => OrderSide::tryFrom((string) $state)?->getLabel()
                            ?? (string) $state
                    )
                    ->color(fn (?string $state): string => OrderSide::tryFrom((string) $state)?->getColor() ?? 'gray'),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('quantity')->label('Qty')->numeric(decimalPlaces: 4)->alignEnd()
                    ->description(fn (array $record): ?string => $record['filled_quantity'] > 0
                        ? 'Filled '.$record['filled_quantity']
                        : null),
                TextColumn::make('limit_price')->label('Limit')
                    ->money(fn (array $record): ?string => $record['currency'])
                    ->placeholder('—')->alignEnd(),
                TextColumn::make('stop_price')->label('Stop')
                    ->money(fn (array $record): ?string => $record['currency'])
                    ->placeholder('—')->alignEnd(),
                TextColumn::make('time_validity')->label('Validity')->placeholder('—'),
                TextColumn::make('status')->badge()->color('warning'),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label('Cancel')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (array $record): string => "Cancel order #{$record['id']}?")
                    ->modalDescription(
                        fn (array $record): string => "{$record['side']} {$record['quantity']} × {$record['ticker']}"
                            ." ({$record['type']})"
                    )
                    ->action(fn (array $record) => $this->cancelOrder((int) $record['id'])),
            ])
            ->emptyStateHeading('No pending orders')
            ->emptyStateDescription(fn (): ?string => $this->tradingApiError)
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->paginated(false)
            ->poll('10s');
    }

    protected function cancelOrder(int $orderId): void
    {
        $account = $this->tradingAccount();

        if ($account === null) {
            return;
        }

        try {
            app(CancelOrder::class)->handle($account, $orderId, auth()->user());
        } catch (Trading212Exception $e) {
            $this->notifyTrading212Failure($e, 'Could not cancel order');

            return;
        }

        Notification::make()->success()->title("Order #{$orderId} cancelled")->send();

        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('placeOrder')
                ->label('Place order')
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->url(Trade::getUrl()),
        ];
    }
}
