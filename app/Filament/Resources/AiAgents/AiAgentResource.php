<?php

namespace App\Filament\Resources\AiAgents;

use App\Enums\Permission;
use App\Filament\Resources\AiAgents\Pages\CreateAiAgent;
use App\Filament\Resources\AiAgents\Pages\EditAiAgent;
use App\Filament\Resources\AiAgents\Pages\ListAiAgents;
use App\Filament\Resources\AiAgents\Pages\ViewAiAgent;
use App\Filament\Resources\AiAgents\RelationManagers\ActivityLogsRelationManager;
use App\Models\AiAgent;
use App\Models\Role;
use App\Models\TradingAccount;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Override;
use UnitEnum;

/**
 * @extends \Filament\Resources\Resource<AiAgent>
 */
class AiAgentResource extends Resource
{
    protected static ?string $model = AiAgent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 1;

    protected static ?string $navigationLabel = 'Agents';

    protected static ?string $modelLabel = 'AI agent';

    protected static ?string $recordTitleAttribute = 'name';

    /**
     * @return Builder<AiAgent>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereBelongsTo(auth()->user());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Agent')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Select::make('role_id')
                            ->label('Role')
                            ->relationship('role', 'name')
                            ->required()
                            ->live()
                            ->helperText('Permissions and limits come from the role.'),
                        Textarea::make('description')
                            ->rows(2)
                            ->columnSpanFull(),
                        Select::make('tradingAccounts')
                            ->label('Trading accounts')
                            ->relationship(
                                'tradingAccounts',
                                'name',
                                modifyQueryUsing: fn (Builder $query): Builder => $query
                                    ->whereBelongsTo(auth()->user()),
                            )
                            ->getOptionLabelFromRecordUsing(
                                fn (TradingAccount $record): string => $record->name
                                    .' — '.$record->environment->getLabel()
                            )
                            ->multiple()
                            ->preload()
                            ->rule(fn (): ValidationRule => new class implements ValidationRule
                            {
                                #[Override]
                                public function validate(string $attribute, mixed $value, Closure $fail): void
                                {
                                    $ids = array_unique(array_map('intval', (array) $value));

                                    if (auth()->user()->tradingAccounts()->whereKey($ids)->count() !== count($ids)) {
                                        $fail('You can only assign your own trading accounts.');
                                    }
                                }
                            })
                            ->helperText(
                                fn (Get $get): ?string => self::liveAccountHint(
                                    $get('role_id'),
                                    $get('tradingAccounts'),
                                )
                            )
                            ->live()
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive agents are rejected by the MCP server.'),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Agent')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('name')->weight('medium'),
                        TextEntry::make('role.name')->label('Role')->badge(),
                        IconEntry::make('is_active')->label('Active')->boolean(),
                        TextEntry::make('description')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('tradingAccounts.name')
                            ->label('Trading accounts')
                            ->badge()
                            ->color(fn (AiAgent $record, string $state): string => $record->tradingAccounts
                                ->firstWhere('name', $state)?->environment->getColor() ?? 'gray')
                            ->placeholder('None'),
                        TextEntry::make('last_used_at')->since()->placeholder('Never'),
                        TextEntry::make('created_at')->dateTime(),
                    ]),
                Section::make('MCP connection')
                    ->description('Connect any MCP client using the streamable HTTP transport.')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('endpoint')
                            ->label('Endpoint')
                            ->state(fn (): string => url('/mcp/trading'))
                            ->fontFamily(FontFamily::Mono)
                            ->copyable(),
                        TextEntry::make('token_prefix')
                            ->label('Header')
                            ->formatStateUsing(fn (string $state): string => "Authorization: Bearer {$state}…")
                            ->fontFamily(FontFamily::Mono)
                            ->helperText('The full token is only shown when created or regenerated.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with(['role', 'tradingAccounts'])
                ->withCount('activityLogs'))
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (AiAgent $record): ?string => $record->description),
                TextColumn::make('role.name')
                    ->label('Role')
                    ->badge()
                    ->color(fn (AiAgent $record): string => $record->role?->hasPermission(Permission::TradeLive)
                        ? 'danger'
                        : 'gray'),
                TextColumn::make('tradingAccounts.name')
                    ->label('Accounts')
                    ->badge()
                    ->color(fn (AiAgent $record, string $state): string => $record->tradingAccounts
                        ->firstWhere('name', $state)?->environment->getColor() ?? 'gray')
                    ->placeholder('None'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('token_prefix')
                    ->label('Token')
                    ->formatStateUsing(fn (string $state): string => "{$state}…")
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('activity_logs_count')
                    ->label('Tool calls')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('last_used_at')
                    ->since()
                    ->placeholder('Never')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->relationship('role', 'name'),
                TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                ListAiAgents::regenerateTokenAction(),
                DeleteAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            ActivityLogsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAiAgents::route('/'),
            'create' => CreateAiAgent::route('/create'),
            'view' => ViewAiAgent::route('/{record}'),
            'edit' => EditAiAgent::route('/{record}/edit'),
        ];
    }

    /**
     * Warn when live accounts are assigned to a role that cannot act on live accounts.
     *
     * @param  array<int|string>|null  $accountIds
     */
    protected static function liveAccountHint(mixed $roleId, ?array $accountIds): ?string
    {
        if (blank($accountIds)) {
            return 'The agent can only act on the accounts selected here.';
        }

        $hasLive = TradingAccount::query()
            ->whereBelongsTo(auth()->user())
            ->whereKey($accountIds)
            ->where('environment', 'live')
            ->exists();

        if (! $hasLive) {
            return 'The agent can only act on the accounts selected here.';
        }

        $role = filled($roleId) ? Role::find($roleId) : null;

        return $role?->hasPermission(Permission::TradeLive)
            ? 'Warning: this agent can trade REAL MONEY on the selected live account(s).'
            : 'Live account selected, but the role lacks the "live" permission — the agent will be blocked from it.';
    }
}
