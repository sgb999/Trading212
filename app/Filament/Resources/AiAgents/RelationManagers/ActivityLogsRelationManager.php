<?php

namespace App\Filament\Resources\AiAgents\RelationManagers;

use App\Filament\Resources\AgentActivityLogs\AgentActivityLogResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

/**
 * The agent's MCP tool-call audit trail (read-only).
 */
class ActivityLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'activityLogs';

    protected static ?string $title = 'Activity';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return AgentActivityLogResource::table($table, showAgent: false);
    }
}
