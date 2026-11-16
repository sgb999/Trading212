<?php

namespace App\Filament\Resources\AiAgents\Pages;

use App\Filament\Resources\AiAgents\AiAgentResource;
use App\Filament\Resources\AiAgents\Concerns\ShowsAgentToken;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Components\Attributes\Exposed;
use Livewire\Attributes\Locked;

class ViewAiAgent extends ViewRecord
{
    use ShowsAgentToken;

    protected static string $resource = AiAgentResource::class;

    /**
     * Plain token handed over (once) from the create page via the session.
     */
    #[Locked]
    public ?string $pendingToken = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $flashed = session()->pull(self::TOKEN_SESSION_KEY);

        if (is_array($flashed) && (string) ($flashed['agent_id'] ?? '') === (string) $this->getRecord()->getKey()) {
            $this->pendingToken = $flashed['token'] ?? null;
        }
    }

    public function shouldMountDefaultActions(): bool
    {
        return filled($this->pendingToken) || parent::shouldMountDefaultActions();
    }

    /**
     * Opens the token modal straight after creation (triggered by `wire:init` on page load).
     */
    #[Exposed]
    public function mountDefaultActions(): void
    {
        if (blank($this->pendingToken)) {
            parent::mountDefaultActions();

            return;
        }

        $token = $this->pendingToken;
        $this->pendingToken = null;

        $this->mountAction('showToken', ['token' => $token]);
    }

    protected function getHeaderActions(): array
    {
        return [
            self::regenerateTokenAction(),
            EditAction::make(),
        ];
    }
}
