<?php

namespace App\Filament\Resources\AiAgents\Pages;

use App\Filament\Resources\AiAgents\AiAgentResource;
use App\Filament\Resources\AiAgents\Concerns\ShowsAgentToken;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAiAgent extends EditRecord
{
    use ShowsAgentToken;

    protected static string $resource = AiAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            self::regenerateTokenAction(),
            DeleteAction::make(),
        ];
    }
}
