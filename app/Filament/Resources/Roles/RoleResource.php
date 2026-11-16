<?php

namespace App\Filament\Resources\Roles;

use App\Enums\Permission;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Models\Role;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Permission sets + trading limits assigned to AI agents.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Grouping shown as the description of each permission checkbox.
     *
     * @return array<string, string>
     */
    public static function permissionDescriptions(): array
    {
        return collect(Permission::cases())
            ->mapWithKeys(fn (Permission $permission): array => [
                $permission->value => collect([
                    match (true) {
                        in_array($permission, Permission::readOnly(), true) => 'Read-only',
                        $permission === Permission::TradeLive => 'Environment · high risk',
                        default => 'Trading',
                    },
                    $permission->getDescription(),
                ])->filter()->implode(' — '),
            ])
            ->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Role')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (Set $set, ?string $state, string $operation): void {
                                if ($operation === 'create') {
                                    $set('slug', Str::slug((string) $state));
                                }
                            }),
                        TextInput::make('slug')
                            ->required()
                            ->alphaDash()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                    ]),
                Section::make('Permissions')
                    ->description('What agents with this role may do through the MCP server.')
                    ->schema([
                        CheckboxList::make('permissions')
                            ->hiddenLabel()
                            ->options(Permission::options())
                            ->descriptions(self::permissionDescriptions())
                            ->columns(2)
                            ->bulkToggleable(),
                    ]),
                Section::make('Limits')
                    ->description('Leave empty for no limit. Enforced on every order an agent places.')
                    ->columns(3)
                    ->schema([
                        TextInput::make('max_order_quantity')
                            ->label('Max quantity per order')
                            ->numeric()
                            ->minValue(0)
                            ->step('any'),
                        TextInput::make('max_order_value')
                            ->label('Max value per order')
                            ->numeric()
                            ->minValue(0)
                            ->step('any')
                            ->helperText('In the account currency. Applies to buys only.'),
                        TextInput::make('max_orders_per_day')
                            ->label('Max orders per day')
                            ->integer()
                            ->minValue(0),
                        TextInput::make('max_daily_loss_percent')
                            ->label('Max daily loss')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step('any')
                            ->suffix('%')
                            ->helperText('Agent BUYs are denied once the account is down more than this today (Europe/London).'),
                        TextInput::make('min_portfolio_value')
                            ->label('Min portfolio value')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1_000_000_000)
                            ->step('any')
                            ->prefix('£')
                            ->helperText('Agent BUYs are denied while the account total value is below this (GBP).'),
                        TagsInput::make('allowed_tickers')
                            ->label('Allowed tickers')
                            ->placeholder('e.g. AAPL_US_EQ')
                            ->helperText('Restrict trading to these tickers. Empty = any ticker, '
                                .'unless "Only listed tickers" is on.')
                            ->splitKeys(['Tab', ',', ' '])
                            ->dehydrateStateUsing(fn (?array $state): ?array => blank($state)
                                ? null
                                : array_values(array_map(
                                    fn (string $ticker): string => strtoupper(trim($ticker)),
                                    $state,
                                )))
                            ->columnSpanFull(),
                        Toggle::make('restrict_to_allowed_tickers')
                            ->label('Only listed tickers')
                            ->helperText('When on, an empty ticker list denies every ticker.')
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('aiAgents'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Role $record): ?string => $record->description
                        ? Str::limit($record->description, 80)
                        : null),
                TextColumn::make('slug')->color('gray')->toggleable(),
                TextColumn::make('permissions')
                    ->label('Permissions')
                    ->state(
                        fn (Role $record): string => count($record->permissions ?? []).' / '.count(Permission::cases())
                    )
                    ->badge()
                    ->color(
                        fn (Role $record): string => $record->hasPermission(Permission::TradeLive) ? 'danger' : 'gray'
                    )
                    ->tooltip(fn (Role $record): string => collect($record->permissions ?? [])
                        ->map(fn (string $value): string => Permission::tryFrom($value)?->getLabel() ?? $value)
                        ->implode(', ')),
                TextColumn::make('limits')
                    ->state(fn (Role $record): string => collect([
                        $record->max_order_quantity !== null ? 'qty ≤ '.(float) $record->max_order_quantity : null,
                        $record->max_order_value !== null ? 'value ≤ '.(float) $record->max_order_value : null,
                        $record->max_orders_per_day !== null ? "{$record->max_orders_per_day}/day" : null,
                        $record->max_daily_loss_percent !== null
                            ? 'daily loss ≤ '.(float) $record->max_daily_loss_percent.'%'
                            : null,
                        $record->min_portfolio_value !== null ? 'value ≥ £'.(float) $record->min_portfolio_value : null,
                        filled($record->allowed_tickers)
                            ? count($record->allowed_tickers).' tickers'
                            : ($record->restrict_to_allowed_tickers ? 'no tickers allowed' : null),
                    ])->filter()->implode(' · ') ?: 'None')
                    ->color('gray'),
                TextColumn::make('ai_agents_count')
                    ->label('Agents')
                    ->badge()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->disabled(fn (Role $record): bool => $record->ai_agents_count > 0)
                    ->tooltip(fn (Role $record): ?string => $record->ai_agents_count > 0
                        ? 'Reassign its agents first'
                        : null),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
