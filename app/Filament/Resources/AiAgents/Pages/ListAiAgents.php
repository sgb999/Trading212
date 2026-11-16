<?php

namespace App\Filament\Resources\AiAgents\Pages;

use App\Filament\Resources\AiAgents\AiAgentResource;
use App\Filament\Resources\AiAgents\Concerns\ShowsAgentToken;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiAgents extends ListRecords
{
    use ShowsAgentToken;

    protected static string $resource = AiAgentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
