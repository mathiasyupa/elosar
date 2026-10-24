<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MedicalServiceResource;
use App\Models\MedicalService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Manage the workspace service catalog (aranceles) from the doctor app — so a
 * doctor or the clinic can add the services they provide (e.g. "Consulta
 * Gineco-Obstétrica") without the admin panel. workspace_id is injected by
 * BelongsToWorkspace; WorkspaceScope keeps it tenant-isolated.
 */
class MedicalServiceController extends Controller
{
    /** Full catalog (active + inactive) for the management screen. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = MedicalService::query()->orderBy('sort_order')->orderBy('name');

        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        return MedicalServiceResource::collection($query->get());
    }

    public function store(Request $request): MedicalServiceResource
    {
        $data = $this->validateData($request);

        $service = MedicalService::create($data);

        return MedicalServiceResource::make($service);
    }

    public function update(Request $request, int $id): MedicalServiceResource
    {
        $service = MedicalService::findOrFail($id);
        $service->update($this->validateData($request));

        return MedicalServiceResource::make($service);
    }

    /**
     * Remove a service from the catalog. Safe for billing history: invoice_items
     * and appointments reference medical_service_id with nullOnDelete and keep
     * their own snapshotted description, so past documents stay intact.
     * findOrFail runs through WorkspaceScope, so a foreign-workspace id 404s.
     */
    public function destroy(int $id): Response
    {
        MedicalService::findOrFail($id)->delete();

        return response()->noContent();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'category' => ['nullable', 'in:'.implode(',', array_keys(MedicalService::CATEGORIES))],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'iva_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // A doctor can add a service name fast; price/IVA default to 0 and the
        // clinic/billing can refine them later.
        $data['base_price'] = $data['base_price'] ?? 0;
        $data['iva_rate'] = $data['iva_rate'] ?? 0;
        $data['category'] = $data['category'] ?? 'general';

        return $data;
    }
}
