<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkspaceResource;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Lists the workspaces the authenticated user may switch between (doctors:
 * personal + clinics; staff: clinics only). Runs with `auth:sanctum` only (no
 * workspace header required) — this is the endpoint the client uses to discover
 * and choose an active workspace. Each workspace carries the user's role,
 * ownership and permission set.
 */
class WorkspaceController extends Controller
{
    public function index(
        Request $request,
        ActiveWorkspace $activeWorkspace,
        WorkspacePermissionService $permissions,
    ): AnonymousResourceCollection {
        $workspaces = $permissions->decorate($request->user(), $activeWorkspace->available());

        // The selector renders every workspace's commercial state, so load the
        // entitlements once instead of one query per row.
        $workspaces->loadMissing('entitlement');

        return WorkspaceResource::collection($workspaces);
    }
}
