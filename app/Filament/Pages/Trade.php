<?php

namespace App\Filament\Pages;

use App\Actions\PlaceOrder;
use App\Data\OrderRequest;
use App\Enums\OrderSide;
use App\Enums\OrderType;
use App\Enums\TimeValidity;
use App\Filament\Concerns\InteractsWithTradingAccount;
use App\Services\Trading212\Exceptions\OrderOutcomeUnknownException;
use App\Services\Trading212\Exceptions\Trading212Exception;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use UnitEnum;

/**
 * Order ticket for the active trading account. Live accounts require an explicit confirmation.
 *
 * Can be prefilled via the query string: `?ticker=AAPL_US_EQ&side=SELL&quantity=2`.
 *
 * @property-read Schema $form
 */
class Trade extends Page
{
    use InteractsWithTradingAccount;

    protected static string|UnitEnum|null $navigationGroup = 'Trading';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 2;

    protected static ?string $title = 'Place order';

    protected static ?string $navigationLabel = 'Trade';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $side = OrderSide::tryFrom(strtoupper((string) request()->query('side'))) ?? OrderSide::Buy;
        $quantity = request()->query('quantity');

        $this->form->fill([
            'ticker' => filled(request()->query('ticker')) ? (string) request()->query('ticker') : null,
            'side' => $side->value,
            'type' => OrderType::Market->value,
            'quantity' => is_numeric($quantity) && $quantity > 0 ? (float) $quantity : null,
            'time_validity' => TimeValidity::Day->value,
            'extended_hours' => false,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Callout::make('Live account — real money')
                    ->description(
                        'Orders placed here are sent to your LIVE Trading 212 account and executed with real money. '
                        .'You will be asked to confirm.'
                    )
                    ->icon(Heroicon::OutlinedExclamationTriangle)
                    ->danger()
                    ->visible(fn (): bool => (bool) $this->tradingAccount()?->isLive()),
                Callout::make('No trading account')
                    ->description(
                        'Connect a Trading 212 account under Settings → Trading accounts before placing orders.'
                    )
                    ->warning()
                    ->visible(fn (): bool => $this->tradingAccount() === null),
                Section::make(fn (): string => 'New order · '.($this->tradingAccount()->name ?? '—'))
                    ->description(fn (): ?string => $this->tradingAccount()?->environment->getLabel())
                    ->columns(2)
                    ->schema([
                        Select::make('ticker')
                            ->label('Instrument')
                            ->placeholder('Search by ticker, name or ISIN…')
                            ->searchable()
                            ->searchDebounce(400)
                            ->searchPrompt('Type a ticker, company name or ISIN')
                            ->getSearchResultsUsing(
                                fn (string $search): array => $this->searchInstrumentOptions($search)
                            )
                            ->getOptionLabelUsing(fn (?string $value): ?string => $this->instrumentLabel($value))
                            ->required()
                            ->columnSpanFull(),
                        ToggleButtons::make('side')
                            ->options(OrderSide::class)
                            ->colors([OrderSide::Buy->value => 'success', OrderSide::Sell->value => 'danger'])
                            ->icons([
                                OrderSide::Buy->value => Heroicon::OutlinedArrowTrendingUp,
                                OrderSide::Sell->value => Heroicon::OutlinedArrowTrendingDown,
                            ])
                            ->inline()
                            ->required()
                            ->live(),
                        ToggleButtons::make('type')
                            ->label('Order type')
                            ->options(OrderType::class)
                            ->inline()
                            ->required()
                            ->live(),
                        TextInput::make('quantity')
                            ->label('Quantity (shares)')
                            ->numeric()
                            ->required()
                            ->rule('gt:0')
                            ->step('any'),
                        TextInput::make('limit_price')
                            ->label('Limit price')
                            ->numeric()
                            ->step('any')
                            ->rule('gt:0')
                            ->required(
                                fn (Get $get): bool => (bool) self::orderType($get('type'))?->requiresLimitPrice()
                            )
                            ->visible(
                                fn (Get $get): bool => (bool) self::orderType($get('type'))?->requiresLimitPrice()
                            ),
                        TextInput::make('stop_price')
                            ->label('Stop price')
                            ->numeric()
                            ->step('any')
                            ->rule('gt:0')
                            ->required(
                                fn (Get $get): bool => (bool) self::orderType($get('type'))?->requiresStopPrice()
                            )
                            ->visible(
                                fn (Get $get): bool => (bool) self::orderType($get('type'))?->requiresStopPrice()
                            ),
                        Select::make('time_validity')
                            ->label('Time validity')
                            ->options(TimeValidity::class)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get): bool => self::orderType($get('type')) !== OrderType::Market),
                        Toggle::make('extended_hours')
                            ->label('Allow extended-hours execution')
                            ->inline(false)
                            ->visible(fn (Get $get): bool => self::orderType($get('type')) === OrderType::Market),
                    ]),
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('mountAction(\'placeOrder\')')
                ->footer([
                    Actions::make([$this->placeOrderAction()])->key('form-actions'),
                ]),
        ]);
    }

    public function placeOrderAction(): Action
    {
        $isLive = fn (): bool => (bool) $this->tradingAccount()?->isLive();

        return Action::make('placeOrder')
            ->label(fn (): string => $isLive() ? 'Place LIVE order' : 'Place order')
            ->icon(fn (): Heroicon => $isLive()
                ? Heroicon::OutlinedExclamationTriangle
                : Heroicon::OutlinedPaperAirplane)
            ->color(fn (): string => $isLive() ? 'danger' : 'primary')
            ->disabled(fn (): bool => $this->tradingAccount() === null)
            ->mountUsing(fn () => $this->form->validate())
            ->requiresConfirmation($isLive)
            ->modalHidden(fn (): bool => ! $isLive())
            ->modalHeading('Confirm LIVE order')
            ->modalDescription(fn (): string => 'This sends a REAL-MONEY order to “'
                .$this->tradingAccount()?->name.'”: '
                .$this->orderSummary().'. Are you sure?')
            ->modalIcon(Heroicon::OutlinedExclamationTriangle)
            ->modalIconColor('danger')
            ->modalSubmitActionLabel('Yes, place live order')
            ->action(fn () => $this->placeOrder());
    }

    protected function placeOrder(): void
    {
        $account = $this->tradingAccount();

        if ($account === null) {
            Notification::make()->danger()->title('No trading account selected')->send();

            return;
        }

        $data = $this->form->getState();

        try {
            $orderRequest = OrderRequest::fromArray([
                'ticker' => (string) $data['ticker'],
                'type' => $data['type'],
                'side' => $data['side'],
                'quantity' => $data['quantity'],
                'limit_price' => $data['limit_price'] ?? null,
                'stop_price' => $data['stop_price'] ?? null,
                'time_validity' => $data['time_validity'] ?? null,
                'extended_hours' => $data['extended_hours'] ?? false,
            ]);
        } catch (InvalidArgumentException $e) {
            Notification::make()->danger()->title('Invalid order')->body($e->getMessage())->send();

            return;
        }

        try {
            $tradeOrder = app(PlaceOrder::class)->handle($account, $orderRequest, auth()->user());
        } catch (OrderOutcomeUnknownException $e) {
            Notification::make()
                ->warning()
                ->title('Order outcome unknown')
                ->body($e->getMessage())
                ->persistent()
                ->send();

            return;
        } catch (Trading212Exception $e) {
            $this->notifyTrading212Failure($e, 'Order rejected by Trading 212');

            return;
        }

        Notification::make()
            ->success()
            ->title('Order placed')
            ->body(sprintf(
                '%s %s × %s (%s) — status %s',
                $tradeOrder->side->getLabel(),
                rtrim(rtrim(number_format((float) $tradeOrder->quantity, 8, '.', ''), '0'), '.'),
                $tradeOrder->ticker,
                $tradeOrder->type->getLabel(),
                $tradeOrder->external_status ?? $tradeOrder->status->getLabel(),
            ))
            ->send();

        $this->form->fill([
            ...$this->data,
            'quantity' => null,
            'limit_price' => null,
            'stop_price' => null,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function searchInstrumentOptions(string $search): array
    {
        $account = $this->tradingAccount();

        if ($account === null || mb_strlen(trim($search)) < 1) {
            return [];
        }

        try {
            $instruments = $account->client()->searchInstruments($search, 30);
        } catch (Trading212Exception $e) {
            $this->notifyTrading212Failure($e, 'Instrument search failed');

            return [];
        }

        return collect($instruments)
            ->mapWithKeys(fn (array $instrument): array => [
                (string) $instrument['ticker'] => self::formatInstrument($instrument),
            ])
            ->all();
    }

    protected function instrumentLabel(?string $ticker): ?string
    {
        if (blank($ticker)) {
            return null;
        }

        $instrument = $this->fetchFromTrading212(
            fn ($client): ?array => collect($client->searchInstruments($ticker, 50))->firstWhere('ticker', $ticker),
        );

        return $instrument ? self::formatInstrument($instrument) : $ticker;
    }

    /**
     * @param  array<string, mixed>  $instrument
     */
    protected static function formatInstrument(array $instrument): string
    {
        $short = $instrument['shortName'] ?? $instrument['ticker'];
        $meta = array_filter([
            $instrument['ticker'] ?? null,
            $instrument['currencyCode'] ?? null,
            $instrument['type'] ?? null,
        ]);

        return trim("{$short} — ".($instrument['name'] ?? '').' ('.implode(' · ', $meta).')');
    }

    protected function orderSummary(): string
    {
        $side = OrderSide::tryFrom((string) ($this->data['side'] ?? ''))?->getLabel() ?? '?';
        $type = self::orderType($this->data['type'] ?? null);

        $summary = "{$side} ".($this->data['quantity'] ?? '?')
            .' × '.($this->data['ticker'] ?? '?')
            .' ('.($type?->getLabel() ?? '?').')';

        if ($type?->requiresLimitPrice()) {
            $summary .= ' limit '.($this->data['limit_price'] ?? '?');
        }

        if ($type?->requiresStopPrice()) {
            $summary .= ' stop '.($this->data['stop_price'] ?? '?');
        }

        return $summary;
    }

    protected static function orderType(mixed $value): ?OrderType
    {
        return $value instanceof OrderType ? $value : OrderType::tryFrom((string) $value);
    }
}
