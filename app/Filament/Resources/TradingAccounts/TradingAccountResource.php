<?php

namespace App\Filament\Resources\TradingAccounts;

use App\Enums\Trading212Environment;
use App\Filament\Resources\TradingAccounts\Actions\TestConnectionAction;
use App\Filament\Resources\TradingAccounts\Pages\CreateTradingAccount;
use App\Filament\Resources\TradingAccounts\Pages\EditTradingAccount;
use App\Filament\Resources\TradingAccounts\Pages\ListTradingAccounts;
use App\Filament\Support\ActiveTradingAccount;
use App\Models\TradingAccount;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * @extends \Filament\Resources\Resource<TradingAccount>
 */
class TradingAccountResource extends Resource
{
    protected static ?string $model = TradingAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Users only ever see their own accounts.
     *
     * @return Builder<TradingAccount>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g. ISA, Demo playground'),
                        Select::make('environment')
                            ->options(Trading212Environment::class)
                            ->default(Trading212Environment::Demo)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->live(),
                        Callout::make('Live account')
                            ->description(
                                'This account trades real money. Orders placed from this platform '
                                .'(including by AI agents with the "live" permission) are executed for real.'
                            )
                            ->danger()
                            ->icon(Heroicon::OutlinedExclamationTriangle)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => self::isLive($get('environment'))),
                        Toggle::make('is_default')
                            ->label('Default account')
                            ->helperText('Selected automatically when you sign in.'),
                    ]),
                Section::make('API credentials')
                    ->description(fn (string $operation): string => $operation === 'edit'
                        ? 'Stored encrypted and never shown again. Leave blank to keep the current credentials.'
                        : 'Generate an API key in Trading 212 → Settings → API. Stored encrypted.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('api_key')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->formatStateUsing(fn (): null => null)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(512),
                        TextInput::make('api_secret')
                            ->label('API secret')
                            ->password()
                            ->revealable()
                            ->autocomplete('off')
                            ->formatStateUsing(fn (): null => null)
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->maxLength(512),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('aiAgents'))
            ->defaultSort('is_default', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (TradingAccount $record): ?string => $record->is(ActiveTradingAccount::get())
                        ? 'Active in this session'
                        : null),
                TextColumn::make('environment')
                    ->badge()
                    ->icon(fn (TradingAccount $record): Heroicon => $record->isLive()
                        ? Heroicon::ExclamationTriangle
                        : Heroicon::Beaker),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                TextColumn::make('currency')
                    ->placeholder('—'),
                TextColumn::make('external_account_id')
                    ->label('T212 account #')
                    ->placeholder('Not verified'),
                TextColumn::make('ai_agents_count')
                    ->label('Agents')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_synced_at')
                    ->since()
                    ->placeholder('Never')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('environment')
                    ->options(Trading212Environment::class),
            ])
            ->recordActions([
                Action::make('activate')
                    ->label('Use')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('primary')
                    ->hidden(fn (TradingAccount $record): bool => $record->is(ActiveTradingAccount::get()))
                    ->action(function (TradingAccount $record, $livewire): void {
                        ActiveTradingAccount::set($record);

                        $livewire->redirect(static::getUrl());
                    }),
                TestConnectionAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTradingAccounts::route('/'),
            'create' => CreateTradingAccount::route('/create'),
            'edit' => EditTradingAccount::route('/{record}/edit'),
        ];
    }

    private static function isLive(mixed $environment): bool
    {
        $environment = $environment instanceof Trading212Environment
            ? $environment
            : Trading212Environment::tryFrom((string) $environment);

        return $environment === Trading212Environment::Live;
    }
}
