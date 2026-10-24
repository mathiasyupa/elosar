<?php

namespace App\Models\Concerns;

use App\Models\Scopes\WorkspaceScope;
use App\Models\Workspace;
use App\Support\ActiveWorkspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace isolation.
 *
 * Trait that makes a model tenant-aware:
 *  1. Applies the global WorkspaceScope (filters reads by the active workspace).
 *  2. Auto-fills workspace_id on create when the record has none and an
 *     active workspace exists in the session.
 *
 * Used by Patient, Appointment, MedicalRecord, Invoice, Payment,
 * MedicalService and CreditNote.
 */
trait BelongsToWorkspace
{
    public static function bootBelongsToWorkspace(): void
    {
        static::addGlobalScope(new WorkspaceScope());

        static::creating(function ($model) {
            if (! empty($model->workspace_id)) {
                return;
            }

            $activeId = app(ActiveWorkspace::class)->id();

            if ($activeId !== null) {
                $model->workspace_id = $activeId;
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'workspace_id');
    }
}
