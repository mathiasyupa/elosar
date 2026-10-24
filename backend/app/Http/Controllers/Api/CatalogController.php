<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Cie10CodeResource;
use App\Http\Resources\MedicalServiceResource;
use App\Http\Resources\MedicationResource;
use App\Http\Resources\StudyResource;
use App\Models\Cie10Code;
use App\Models\MedicalService;
use App\Models\Medication;
use App\Models\Specialty;
use App\Models\Study;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Catalogs the app needs to build forms: the workspace fee schedule (medical
 * services) and the global CIE-10 search. MedicalService is workspace-scoped;
 * Cie10Code is a global catalog.
 */
class CatalogController extends Controller
{
    /**
     * Role reference for the team screen: what each clinic role grants, derived
     * from the real permission matrix (WorkspacePermissionService::catalog) so
     * the help can never contradict what the server enforces. Static catalog —
     * no workspace needed.
     */
    public function roles(WorkspacePermissionService $permissions): JsonResponse
    {
        return response()->json(['data' => $permissions->catalog()]);
    }

    /**
     * Global specialty catalog (public — no auth). Used by the doctor
     * self-registration form before any session exists.
     */
    public function specialties(): JsonResponse
    {
        return response()->json([
            'data' => Specialty::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Self-service: add a specialty missing from the catalog (public — the
     * doctor self-registration form calls this before any session exists).
     * Case-insensitive match against existing names to avoid near-duplicates.
     */
    public function storeSpecialty(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($data['name']);

        $specialty = Specialty::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
            ?? Specialty::create(['name' => $name]);

        return response()->json([
            'data' => ['id' => $specialty->id, 'name' => $specialty->name],
        ], 201);
    }

    /**
     * Active fee schedule for the active workspace (invoice item picker).
     */
    public function medicalServices(Request $request): AnonymousResourceCollection
    {
        $query = MedicalService::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        return MedicalServiceResource::collection($query->get());
    }

    /**
     * CIE-10 categories for browse/filter in the diagnosis picker.
     */
    public function cie10Categories(): JsonResponse
    {
        $categories = Cie10Code::query()
            ->whereNotNull('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        return response()->json(['data' => $categories->values()]);
    }

    /**
     * CIE-10 search by code or description (diagnosis picker).
     *
     * Query params:
     *   q         — search term (min 2 chars unless `category` is set)
     *   category  — browse/filter by category label
     *   limit     — max results (default 50, cap 100)
     */
    public function cie10(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->query('q'));
        $category = trim((string) $request->query('category'));
        $limit = min(max($request->integer('limit', 50), 1), 100);

        $hasTerm = mb_strlen($term) >= 2;
        $hasCategory = $category !== '';

        if (! $hasTerm && ! $hasCategory) {
            return Cie10CodeResource::collection(collect())
                ->additional(['meta' => ['total' => 0, 'limit' => $limit, 'truncated' => false]]);
        }

        $query = Cie10Code::query();

        if ($hasCategory) {
            $query->where('category', $category);
        }

        if ($hasTerm) {
            $pattern = '%'.$term.'%';
            $prefix = $term.'%';

            $query->where(function ($q) use ($pattern): void {
                $q->where('code', 'ilike', $pattern)
                    ->orWhere('description', 'ilike', $pattern)
                    ->orWhereRaw('unaccent(description) ILIKE unaccent(?)', [$pattern]);
            });

            $query->orderByRaw(
                'CASE
                    WHEN code ILIKE ? THEN 0
                    WHEN unaccent(description) ILIKE unaccent(?) THEN 1
                    WHEN description ILIKE ? THEN 2
                    ELSE 3
                END',
                [$prefix, $prefix, $prefix]
            );
        }

        $query->orderBy('code');

        $total = (clone $query)->count();
        $codes = $query->limit($limit)->get();

        return Cie10CodeResource::collection($codes)
            ->additional([
                'meta' => [
                    'total' => $total,
                    'limit' => $limit,
                    'truncated' => $total > $codes->count(),
                ],
            ]);
    }

    /**
     * Medication search: global catalog + workspace-specific entries.
     */
    public function medications(Request $request): AnonymousResourceCollection
    {
        $term = trim((string) $request->query('q'));
        $workspaceId = app(ActiveWorkspace::class)->id();

        $query = Medication::query()
            ->where('is_active', true)
            ->where(function ($q) use ($workspaceId): void {
                $q->whereNull('workspace_id');
                if ($workspaceId !== null) {
                    $q->orWhere('workspace_id', $workspaceId);
                }
            })
            ->orderByRaw('workspace_id IS NULL DESC')
            ->orderBy('sort_order')
            ->orderBy('name');

        if (mb_strlen($term) >= 2) {
            $pattern = '%'.$term.'%';
            $query->where(function ($q) use ($pattern): void {
                $q->where('name', 'ilike', $pattern)
                    ->orWhereRaw('unaccent(name) ILIKE unaccent(?)', [$pattern]);
            });
        }

        return MedicationResource::collection($query->limit(25)->get());
    }

    /**
     * Save a workspace-specific medication to the clinic catalog.
     */
    public function storeMedication(Request $request): JsonResponse
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'concentration' => ['nullable', 'string', 'max:100'],
            'default_dose_quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'default_dose_unit' => ['nullable', 'string', Rule::in(array_keys(Medication::DOSE_UNITS))],
            'default_frequency' => ['nullable', 'string', Rule::in(array_keys(Medication::FREQUENCIES))],
            'default_duration_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'route' => ['nullable', 'string', Rule::in(array_keys(Medication::ROUTES))],
            'is_controlled' => ['nullable', 'boolean'],
            'default_instructions' => ['nullable', 'string'],
        ]);

        $medication = Medication::create([
            'workspace_id' => $workspaceId,
            'name' => $data['name'],
            'concentration' => $data['concentration'] ?? null,
            'default_dose_quantity' => $data['default_dose_quantity'] ?? 1,
            'default_dose_unit' => $data['default_dose_unit'] ?? 'tablet',
            'default_frequency' => $data['default_frequency'] ?? 'every_8_hours',
            'default_duration_days' => $data['default_duration_days'] ?? 7,
            'route' => $data['route'] ?? 'oral',
            'is_controlled' => (bool) ($data['is_controlled'] ?? false),
            'default_instructions' => $data['default_instructions'] ?? null,
            'is_active' => true,
        ]);

        return MedicationResource::make($medication)
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Remove a workspace-specific medication (global entries are immutable).
     */
    public function destroyMedication(int $id): JsonResponse
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        $medication = Medication::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->firstOrFail();

        $medication->delete();

        return response()->json(status: 204);
    }

    /**
     * Orderable studies catalog (lab / imaging): global + workspace-specific
     * entries. Requires ?type=laboratory|imaging; optional ?q search term. The
     * full active list is returned (the order builder groups by category client
     * side) unless a search term narrows it.
     */
    public function studies(Request $request): AnonymousResourceCollection
    {
        $type = $request->query('type');
        $term = trim((string) $request->query('q'));
        $workspaceId = app(ActiveWorkspace::class)->id();

        $query = Study::query()
            ->where('is_active', true)
            ->where(function ($q) use ($workspaceId): void {
                $q->whereNull('workspace_id');
                if ($workspaceId !== null) {
                    $q->orWhere('workspace_id', $workspaceId);
                }
            })
            ->when(in_array($type, [Study::TYPE_LABORATORY, Study::TYPE_IMAGING], true), fn ($q) => $q->where('type', $type))
            ->orderBy('sort_order')
            ->orderBy('name');

        if (mb_strlen($term) >= 2) {
            $pattern = '%'.$term.'%';
            $query->where(function ($q) use ($pattern): void {
                $q->where('name', 'ilike', $pattern)
                    ->orWhere('category', 'ilike', $pattern)
                    ->orWhereRaw('unaccent(name) ILIKE unaccent(?)', [$pattern]);
            });
        }

        return StudyResource::collection($query->limit(400)->get());
    }

    /** Save a workspace-specific orderable study (the imaging picker "Nuevo"). */
    public function storeStudy(Request $request): JsonResponse
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        $data = $request->validate([
            'type' => ['required', Rule::in([Study::TYPE_LABORATORY, Study::TYPE_IMAGING])],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:80'],
            'code' => ['nullable', 'string', 'max:40'],
        ]);

        $study = Study::create([
            'workspace_id' => $workspaceId,
            'type' => $data['type'],
            'category' => $data['category'] ?? null,
            'name' => $data['name'],
            'code' => $data['code'] ?? null,
            'is_active' => true,
        ]);

        return StudyResource::make($study)->response()->setStatusCode(201);
    }

    /** Remove a workspace-specific study (global entries are immutable). */
    public function destroyStudy(int $id): JsonResponse
    {
        $workspaceId = app(ActiveWorkspace::class)->id();

        $study = Study::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($id)
            ->firstOrFail();

        $study->delete();

        return response()->json(status: 204);
    }
}
