<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One preference a doctor taught Elo ("prefiero ibuprofeno a diclofenaco").
 * Scoped to the workspace by the trait and to the doctor by `mine()`: rules
 * are personal, not even the clinic owner reads another doctor's.
 */
class EloRule extends Model
{
    use BelongsToWorkspace;

    public const MAX_PER_AGENT = 30;

    protected $fillable = ['workspace_id', 'user_id', 'agent', 'text', 'enabled'];

    protected $casts = ['enabled' => 'boolean'];

    /** @param Builder<EloRule> $query */
    public function scopeMine(Builder $query, int $userId): void
    {
        $query->where('user_id', $userId);
    }
}
