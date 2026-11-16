<?php

namespace App\Filament\Resources\AgentActivityLogs;

use App\Filament\Resources\AgentActivityLogs\Pages\ListAgentActivityLogs;
use App\Models\AgentActivityLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
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
 * Read-only audit of every MCP tool call made by the user's AI agents.
 *
 * @extends \Filament\Resources\Resource<AgentActivityLog>
 */
class AgentActivityLogResource extends Resource
{
    protected static ?string $model = AgentActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static string|UnitEnum|null $navigationGroup = 'AI';

    protected static ?int $navigationSort = 3;

    protected static ?string $navigationLabel = 'Activity';

    protected static ?string $modelLabel = 'activity entry';

    protected static ?string $pluralModelLabel = 'agent activity';

    protected static ?string $slug = 'agent-activity';

    protected static ?string $recordTitleAttribute = 'tool';

    /**
     * @return Builder<AgentActivityLog>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('aiAgent', fn (Builder $query): Builder => $query->whereBelongsTo(auth()->user()))
            ->with(['aiAgent', 'tradingAccount']);
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

    public static function outcomeColor(?string $outcome): string
    {
        return match ($outcome) {
            AgentActivityLog::OUTCOME_ALLOWED => 'success',
            AgentActivityLog::OUTCOME_DENIED => 'warning',
            AgentActivityLog::OUTCOME_ERROR => 'danger',
            default => 'gray',
        };
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextEntry::make('created_at')->dateTime(),
                TextEntry::make('aiAgent.name')->label('Agent'),
                TextEntry::make('tool')->fontFamily('mono'),
                TextEntry::make('outcome')->badge()->color(fn (?string $state): string => self::outcomeColor($state)),
                TextEntry::make('tradingAccount.name')->label('Account')->placeholder('—'),
                TextEntry::make('message')->placeholder('—')->columnSpanFull(),
                TextEntry::make('arguments')
                    ->columnSpanFull()
                    ->placeholder('—')
                    ->formatStateUsing(fn (mixed $state, AgentActivityLog $record): HtmlString => new HtmlString(
                        '<pre class="text-xs whitespace-pre-wrap">'
                            .e(json_encode($record->arguments, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                            .'</pre>'
                    )),
            ]);
    }

    public static function table(Table $table, bool $showAgent = true): Table
    {
        return $table
            ->recordTitleAttribute('tool')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('When')->since()->dateTimeTooltip()->sortable(),
                TextColumn::make('aiAgent.name')->label('Agent')->visible($showAgent)->searchable(),
                TextColumn::make('tool')->fontFamily('mono')->searchable(),
                TextColumn::make('outcome')->badge()->color(fn (?string $state): string => self::outcomeColor($state)),
                TextColumn::make('tradingAccount.name')->label('Account')->placeholder('—'),
                TextColumn::make('message')->limit(80)
                    ->tooltip(fn (AgentActivityLog $record): ?string => $record->message)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('outcome')
                    ->options([
                        AgentActivityLog::OUTCOME_ALLOWED => 'Allowed',
                        AgentActivityLog::OUTCOME_DENIED => 'Denied',
                        AgentActivityLog::OUTCOME_ERROR => 'Error',
                    ]),
                SelectFilter::make('ai_agent_id')
                    ->label('Agent')
                    ->options(fn (): array => auth()->user()->aiAgents()->orderBy('name')->pluck('name', 'id')->all())
                    ->visible($showAgent),
            ])
            ->recordActions([
                ViewAction::make()->infolist(fn (Schema $schema): Schema => self::infolist($schema)),
            ])
            ->poll('30s');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAgentActivityLogs::route('/'),
        ];
    }
}
