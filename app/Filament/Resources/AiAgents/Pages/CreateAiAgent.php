<?php

namespace App\Filament\Resources\AiAgents\Pages;

use App\Filament\Resources\AiAgents\AiAgentResource;
use App\Models\AiAgent;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAiAgent extends CreateRecord
{
    protected static string $resource = AiAgentResource::class;

    /**
     * Create the agent with a freshly issued token; the plain token is flashed so the view page
     * can show it exactly once.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $agent = new AiAgent($data);
        $agent->user()->associate(auth()->user());

        $token = $agent->issueToken();

        session()->flash(ViewAiAgent::TOKEN_SESSION_KEY, ['agent_id' => $agent->getKey(), 'token' => $token]);

        return $agent;
    }

    protected function getRedirectUrl(): string
    {
        return AiAgentResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
