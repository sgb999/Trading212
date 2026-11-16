<?php

namespace App\Filament\Pages;

use App\Enums\OrderSide;
use App\Filament\Concerns\InteractsWithTradingAccount;
use App\Filament\Tables\PositionsTable;
use App\Services\Trading212\Trading212Client;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Historical orders, dividends and transactions, loaded page by page with Trading 212's cursor pagination.
 */
class History extends Page implements HasTable
{
    use InteractsWithTable;
    use InteractsWithTradingAccount;

    /**
     * @var array<string, string>
     */
    public const array TABS = [
        'orders' => 'Orders',
        'dividends' => 'Dividends',
        'transactions' => 'Transactions',
    ];

    protected static string|UnitEnum|null $navigationGroup = 'Trading';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.history';

    #[Url(as: 'tab')]
    public string $activeTab = 'orders';

    /**
     * Rows loaded so far for the active tab.
     *
     * @var list<array<string, mixed>>
     */
    public array $items = [];

    public ?string $nextCursor = null;

    public ?string $loadError = null;

    public function mount(): void
    {
        if (! array_key_exists($this->activeTab, self::TABS)) {
            $this->activeTab = 'orders';
        }

        $this->loadPage();
    }

    public function setTab(string $tab): void
    {
        if (! array_key_exists($tab, self::TABS)) {
            return;
        }

        $this->activeTab = $tab;
        $this->items = [];
        $this->nextCursor = null;
        $this->loadPage();
    }

    public function loadMore(): void
    {
        if ($this->nextCursor !== null) {
            $this->loadPage($this->nextCursor);
        }
    }

    protected function loadPage(?string $cursor = null): void
    {
        $this->loadError = null;

        $page = $this->fetchFromTrading212(fn (Trading212Client $client): array => match ($this->activeTab) {
            'dividends' => $client->dividends($cursor, limit: 50),
            'transactions' => $client->transactions($cursor, limit: 50),
            default => $client->orderHistory($cursor, limit: 50),
        });

        if ($page === null) {
            $this->loadError = $this->tradingApiError;

            return;
        }

        $rows = array_map(fn (array $item): array => match ($this->activeTab) {
            'dividends' => self::dividendRow($item),
            'transactions' => self::transactionRow($item),
            default => self::orderRow($item),
        }, $page['items']);

        $this->items = [...$this->items, ...$rows];
        $this->nextCursor = self::cursorFrom($page['nextPagePath'] ?? null);
    }

    public function table(Table $table): Table
    {
        $visibleOnTabs = fn (string ...$tabs): \Closure => fn (): bool => in_array($this->activeTab, $tabs, true);

        return $table
            ->records(fn (): array => collect($this->items)
                ->mapWithKeys(fn (array $row, int $index): array => ["{$this->activeTab}-{$index}" => $row])
                ->all())
            ->columns([
                TextColumn::make('date')->label('Date')->dateTime()->sinceTooltip(),
                TextColumn::make('ticker')->label('Instrument')->weight('medium')->placeholder('—')
                    ->description(fn (array $record): ?string => $record['name'] ?? null)
                    ->visible($visibleOnTabs('orders', 'dividends')),
                TextColumn::make('side')->badge()
                    ->formatStateUsing(
                        fn (?string $state): string => OrderSide::tryFrom((string) $state)?->getLabel()
                            ?? (string) $state
                    )
                    ->color(fn (?string $state): string => OrderSide::tryFrom((string) $state)?->getColor() ?? 'gray')
                    ->visible($visibleOnTabs('orders')),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('status')->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        'FILLED' => 'success',
                        'CANCELLED', 'REJECTED' => 'danger',
                        default => 'warning',
                    })
                    ->visible($visibleOnTabs('orders')),
                TextColumn::make('quantity')->label('Qty')->numeric(decimalPlaces: 4)->alignEnd()->placeholder('—')
                    ->visible($visibleOnTabs('orders', 'dividends')),
                TextColumn::make('price')->label('Fill price')
                    ->money(fn (array $record): ?string => $record['instrument_currency'] ?? null)
                    ->placeholder('—')->alignEnd()->visible($visibleOnTabs('orders')),
                TextColumn::make('amount')
                    ->label(fn (): string => $this->activeTab === 'orders' ? 'Net value' : 'Amount')
                    ->money(fn (array $record): ?string => $record['currency'] ?? null)->placeholder('—')->alignEnd(),
                TextColumn::make('pnl')->label('Realised P&L')
                    ->money(fn (array $record): ?string => $record['currency'] ?? null)
                    ->color(fn (?float $state): string => PositionsTable::pnlColor((float) $state))
                    ->placeholder('—')->alignEnd()->visible($visibleOnTabs('orders')),
                TextColumn::make('reference')->placeholder('—')->toggleable()
                    ->visible($visibleOnTabs('transactions', 'dividends')),
            ])
            ->emptyStateHeading(fn (): string => 'No '.strtolower(self::TABS[$this->activeTab]).' yet')
            ->emptyStateDescription(fn (): ?string => $this->loadError)
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox)
            ->paginated(false);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected static function orderRow(array $item): array
    {
        $order = $item['order'] ?? $item;
        $fill = $item['fill'] ?? [];
        $quantity = (float) ($order['filledQuantity'] ?? $fill['quantity'] ?? $order['quantity'] ?? 0);

        return [
            'date' => self::date($fill['filledAt'] ?? $order['createdAt'] ?? null),
            'ticker' => $order['ticker'] ?? $order['instrument']['ticker'] ?? null,
            'name' => $order['instrument']['name'] ?? null,
            'instrument_currency' => $order['instrument']['currency'] ?? $order['currency'] ?? null,
            'side' => $order['side'] ?? ($quantity < 0 ? 'SELL' : 'BUY'),
            'type' => $order['type'] ?? null,
            'status' => $order['status'] ?? null,
            'quantity' => abs($quantity),
            'price' => $fill['price'] ?? null,
            'amount' => $fill['walletImpact']['netValue'] ?? $order['filledValue'] ?? null,
            'currency' => $fill['walletImpact']['currency'] ?? $order['currency'] ?? null,
            'pnl' => $fill['walletImpact']['realisedProfitLoss'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected static function dividendRow(array $item): array
    {
        return [
            'date' => self::date($item['paidOn'] ?? null),
            'ticker' => $item['ticker'] ?? $item['instrument']['ticker'] ?? null,
            'name' => $item['instrument']['name'] ?? null,
            'type' => isset($item['type']) ? str_replace('_', ' ', $item['type']) : null,
            'quantity' => $item['quantity'] ?? null,
            'amount' => $item['amount'] ?? null,
            'currency' => $item['currency'] ?? null,
            'reference' => $item['reference'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected static function transactionRow(array $item): array
    {
        return [
            'date' => self::date($item['dateTime'] ?? null),
            'type' => isset($item['type']) ? str_replace('_', ' ', $item['type']) : null,
            'amount' => $item['amount'] ?? null,
            'currency' => $item['currency'] ?? null,
            'reference' => $item['reference'] ?? null,
        ];
    }

    /**
     * Extract the `cursor` query parameter from Trading 212's `nextPagePath`.
     */
    public static function cursorFrom(?string $nextPagePath): ?string
    {
        if (blank($nextPagePath)) {
            return null;
        }

        parse_str((string) parse_url($nextPagePath, PHP_URL_QUERY), $query);

        return filled($query['cursor'] ?? null) ? (string) $query['cursor'] : null;
    }

    protected static function date(?string $value): ?string
    {
        return $value ? Carbon::parse($value)->toIso8601String() : null;
    }
}
