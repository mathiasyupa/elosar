<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use App\Services\Elo\EloAgents;
use Illuminate\Database\Eloquent\Model;

/** A doctor's on/off switch for one Elo agent. No row means the agent's default (on, except dictation). */
class EloAgentSetting extends Model
{
    use BelongsToWorkspace;

    protected $fillable = ['workspace_id', 'user_id', 'agent', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    public static function isEnabled(int $userId, string $agent): bool
    {
        return (bool) (static::query()->where('user_id', $userId)->where('agent', $agent)->value('enabled') ?? EloAgents::enabledByDefault($agent));
    }
}
