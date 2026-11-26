<?php

namespace App\Traits;

use App\Models\AiAgent;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

trait BelongsToManyAgentsTrait
{
    /**
     * @return BelongsToMany<AiAgent, $this>
     */
    public function aiAgents(): BelongsToMany
    {
        return $this->belongsToMany(AiAgent::class);
    }
}
