<?php

namespace App\Models\Scopes;

use App\Exceptions\WorkspaceContextMissingException;
use App\Support\ActiveWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Multi-tenant global scope by workspace.
 *
 * Automatically filters any tenant-aware model (Patient, Appointment,
 * MedicalRecord, Invoice, …) by the active workspace.
 *
 * Key behavior (FAIL-CLOSED):
 *  - If the scope is explicitly disabled (ActiveWorkspace::runUnguarded) → no
 *    filtering. This is how seeders, CLI maintenance and superadmin reporting
 *    opt into cross-tenant access.
 *  - If there IS an active workspace (authenticated doctor or stateless
 *    binding) → adds `where workspace_id = <active>`.
 *  - Otherwise → throws WorkspaceContextMissingException instead of returning
 *    every tenant's rows. This prevents the silent fail-open leak in jobs,
 *    unauthenticated requests, or any context that forgot to resolve a tenant.
 *
 * The BelongsToWorkspace trait complements it by auto-filling workspace_id
 * when creating records within the active context.
 */
class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $active = app(ActiveWorkspace::class);

        // Explicit, auditable cross-tenant access (seeders, CLI, superadmin).
        if ($active->isUnguarded()) {
            return;
        }

        $workspaceId = $active->id();

        if ($workspaceId !== null) {
            $builder->where($model->getTable() . '.workspace_id', $workspaceId);

            return;
        }

        throw new WorkspaceContextMissingException($model::class);
    }
}
