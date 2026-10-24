<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;

/**
 * What a doctor did with one of Elo's suggestions. No clinical content —
 * only which agent, the decision and how many items — so it can be counted
 * ("7 de cada 10 recetas sugeridas se aceptan") without becoming a copy of
 * the medical record.
 */
class EloSuggestionEvent extends Model
{
    use BelongsToWorkspace;

    public const AGENTS = ['prescription', 'note', 'exams', 'dictation'];

    public const OUTCOMES = ['shown', 'accepted', 'edited', 'rejected'];

    public const UPDATED_AT = null;

    protected $fillable = ['workspace_id', 'user_id', 'agent', 'outcome', 'items_count'];

    protected $casts = ['items_count' => 'integer'];
}
