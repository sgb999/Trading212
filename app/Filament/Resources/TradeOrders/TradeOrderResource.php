<?php

namespace App\Filament\Resources\TradeOrders;

use App\Enums\TradeOrderStatus;
use App\Filament\Resources\TradeOrders\Pages\ListTradeOrders;
use App\Filament\Resources\TradeOrders\Pages\ViewTradeOrder;
use App\Models\AiAgent;
use App\Models\TradeOrder;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Override;
use UnitEnum;

/**
 * Read-only audit log of every order sent through the platform, by the user or an AI agent.
 *
 * @extends \Filament\Resources\Resource<TradeOrder>
 */
class TradeOrderResource extends Resource
{
    protected static ?string $model = TradeOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Trading';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Order log';

    protected static ?string $modelLabel = 'order';

    protected static ?string $recordTitleAttribute = 'ticker';

    /**
     * @return Builder<TradeOrder>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('tradingAccount', fn (Builder $query): Builder => $query->whereBelongsTo(auth()->user()))
            ->with(['tradingAccount', 'placedBy']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    #[Override]
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    #[Override]
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function placedByLabel(TradeOrder $record): string
    {
        return match (true) {
            $record->placedBy instanceof AiAgent => "Agent: {$record->placedBy->name}",
            $record->placedBy instanceof User => $record->placedBy->name,
            default => 'Unknown',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Order')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('ticker')->weight('medium'),
                        TextEntry::make('side')->badge(),
                        TextEntry::make('type')->badge()->color('gray'),
                        TextEntry::make('quantity')->numeric(decimalPlaces: 4),
                        TextEntry::make('limit_price')->numeric(decimalPlaces: 4)->placeholder('—'),
                        TextEntry::make('stop_price')->numeric(decimalPlaces: 4)->placeholder('—'),
                        TextEntry::make('time_validity')->placeholder('—'),
                        IconEntry::make('extended_hours')->boolean(),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make('Outcome')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('status')->badge(),
                        TextEntry::make('external_status')->label('Trading 212 status')->placeholder('—'),
                        TextEntry::make('external_id')->label('Trading 212 order #')->placeholder('—'),
                        TextEntry::make('tradingAccount.name')->label('Account')
                            ->badge()
                            ->color(
                                fn (TradeOrder $record): string => $record->tradingAccount->environment->getColor()
                            ),
                        TextEntry::make('placed_by')
                            ->label('Placed by')
                            ->state(fn (TradeOrder $record): string => self::placedByLabel($record)),
                        TextEntry::make('error')->color('danger')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('response')
                            ->label('Raw response')
                            ->columnSpanFull()
                            ->placeholder('—')
                            ->formatStateUsing(fn (TradeOrder $record): HtmlString => new HtmlString(
                                '<pre class="text-xs whitespace-pre-wrap">'
                                    .e(json_encode($record->response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                                    .'</pre>'
                            )),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('ticker')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('Placed')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('tradingAccount.name')
                    ->label('Account')
                    ->badge()
                    ->color(fn (TradeOrder $record): string => $record->tradingAccount->environment->getColor()),
                TextColumn::make('ticker')->searchable()->weight('medium'),
                TextColumn::make('side')->badge(),
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('quantity')->numeric(decimalPlaces: 4)->alignEnd(),
                TextColumn::make('limit_price')->label('Limit')->numeric(decimalPlaces: 2)
                    ->placeholder('—')->toggleable(),
                TextColumn::make('stop_price')->label('Stop')->numeric(decimalPlaces: 2)
                    ->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')->badge(),
                TextColumn::make('external_status')->label('T212 status')->placeholder('—')->toggleable(),
                TextColumn::make('placed_by')
                    ->label('Placed by')
                    ->state(fn (TradeOrder $record): string => self::placedByLabel($record))
                    ->icon(fn (TradeOrder $record): Heroicon => $record->placedBy instanceof AiAgent
                        ? Heroicon::OutlinedCpuChip
                        : Heroicon::OutlinedUser),
                TextColumn::make('error')->color('danger')->limit(60)
                    ->tooltip(fn (TradeOrder $record): ?string => $record->error)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('trading_account_id')
                    ->label('Account')
                    ->options(fn (): array => auth()->user()->tradingAccounts()->pluck('name', 'id')->all()),
                SelectFilter::make('status')
                    ->options(TradeOrderStatus::class),
                SelectFilter::make('placed_by')
                    ->label('Placed by')
                    ->options(fn (): array => [
                        'user' => 'Me',
                        ...auth()->user()->aiAgents()->orderBy('name')->pluck('name', 'id')
                            ->mapWithKeys(fn (string $name, int $id): array => ["agent:{$id}" => "Agent: {$name}"])
                            ->all(),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        return match (true) {
                            blank($value) => $query,
                            $value === 'user' => $query
                                ->where('placed_by_type', (new User)->getMorphClass())
                                ->where('placed_by_id', auth()->id()),
                            str_starts_with($value, 'agent:') => $query
                                ->where('placed_by_type', (new AiAgent)->getMorphClass())
                                ->where('placed_by_id', (int) str($value)->after('agent:')->toString()),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTradeOrders::route('/'),
            'view' => ViewTradeOrder::route('/{record}'),
        ];
    }
}
