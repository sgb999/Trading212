<?php

namespace App\Filament\Resources\AiAgents\Concerns;

use App\Models\AiAgent;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/**
 * Adds the "show token once" modal and the "regenerate token" action to AI agent pages.
 */
trait ShowsAgentToken
{
    public const string TOKEN_SESSION_KEY = 'ai_agent_plain_token';

    public static function mcpEndpoint(): string
    {
        return url('/mcp/trading');
    }

    /**
     * @return array{
     *     mcpServers: array{
     *         trading-platform: array{type: string, url: string, headers: array{Authorization: string}},
     *     },
     * }
     */
    public static function mcpClientConfig(string $token): array
    {
        return [
            'mcpServers' => [
                'trading-platform' => [
                    'type' => 'http',
                    'url' => self::mcpEndpoint(),
                    'headers' => ['Authorization' => "Bearer {$token}"],
                ],
            ],
        ];
    }

    public function showTokenAction(): Action
    {
        return Action::make('showToken')
            ->modalHeading('Copy the agent token now')
            ->modalDescription(
                'This is the only time the token is shown. Only a SHA-256 hash is stored, '
                .'so it cannot be recovered — regenerate it if you lose it.'
            )
            ->modalIcon(Heroicon::OutlinedKey)
            ->modalIconColor('warning')
            ->modalWidth(Width::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('I have copied the token')
            ->closeModalByClickingAway(false)
            ->schema(fn (array $arguments): array => [
                TextEntry::make('token')
                    ->label('Bearer token')
                    ->state($arguments['token'] ?? '')
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->copyMessage('Token copied')
                    ->extraAttributes(['data-testid' => 'agent-token']),
                TextEntry::make('endpoint')
                    ->label('MCP endpoint (streamable HTTP)')
                    ->state(self::mcpEndpoint())
                    ->fontFamily(FontFamily::Mono)
                    ->copyable(),
                TextEntry::make('header')
                    ->label('Request header')
                    ->state('Authorization: Bearer '.($arguments['token'] ?? ''))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable(),
                TextEntry::make('config')
                    ->label('MCP client config (e.g. Claude Desktop, Cursor)')
                    ->state(json_encode(
                        self::mcpClientConfig($arguments['token'] ?? ''),
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                    ))
                    ->formatStateUsing(fn (string $state): HtmlString => new HtmlString(
                        '<pre class="overflow-x-auto text-xs">'.e($state).'</pre>'
                    ))
                    ->copyable()
                    ->copyableState(fn (string $state): string => $state),
                TextEntry::make('cli')
                    ->label('Claude Code')
                    ->state(
                        'claude mcp add --transport http trading-platform '.self::mcpEndpoint()
                        .' --header "Authorization: Bearer '.($arguments['token'] ?? '').'"'
                    )
                    ->fontFamily(FontFamily::Mono)
                    ->copyable(),
                Callout::make('Keep it secret')
                    ->description(
                        'Anyone with this token can act as the agent within its role\'s permissions and limits.'
                    )
                    ->warning(),
            ]);
    }

    public static function regenerateTokenAction(): Action
    {
        return Action::make('regenerateToken')
            ->label('Regenerate token')
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading('Regenerate agent token?')
            ->modalDescription('The current token stops working immediately. Any MCP client using it must be updated.')
            ->modalSubmitActionLabel('Regenerate')
            ->action(function (AiAgent $record, $livewire): void {
                $token = $record->issueToken();

                $livewire->replaceMountedAction('showToken', ['token' => $token]);
            });
    }
}
