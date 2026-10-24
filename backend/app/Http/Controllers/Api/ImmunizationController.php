<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreImmunizationRequest;
use App\Http\Resources\ImmunizationResource;
use App\Http\Resources\VaccineResource;
use App\Models\Patient;
use App\Models\PatientImmunization;
use App\Models\Vaccine;
use App\Support\AccessLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * Patient vaccination card (Ecuador PAI). The vaccine catalog is global; the
 * administered doses are workspace-scoped clinical data resolved through the
 * scoped patient (404 outside the active workspace). Administration is attributed
 * to the acting USER so nurses (who have no Doctor record) are captured.
 */
class ImmunizationController extends Controller
{
    /** Global vaccine catalog (rows of the vaccination card). */
    public function vaccines(): AnonymousResourceCollection
    {
        $vaccines = Vaccine::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return VaccineResource::collection($vaccines);
    }

    /** A patient's administered doses. */
    public function index(int $patientId, AccessLogger $accessLogger): AnonymousResourceCollection
    {
        $patient = Patient::findOrFail($patientId);

        $accessLogger->log('patient', $patient->id, 'immunizations');

        $doses = PatientImmunization::query()
            ->where('patient_id', $patient->id)
            ->orderBy('administered_at')
            ->get();

        return ImmunizationResource::collection($doses);
    }

    /** Record an administered dose. */
    public function store(StoreImmunizationRequest $request, int $patientId): JsonResponse
    {
        $patient = Patient::findOrFail($patientId);

        $data = $request->validated();

        // Only honour a vaccine_id that exists in the global catalog; otherwise
        // keep just the snapshot name (custom / off-catalog vaccine).
        $vaccineId = null;
        if (! empty($data['vaccine_id'])) {
            $vaccineId = Vaccine::query()->whereKey($data['vaccine_id'])->value('id');
        }

        $dose = PatientImmunization::create([
            'patient_id' => $patient->id,
            'vaccine_id' => $vaccineId,
            'vaccine_name' => $data['vaccine_name'],
            'dose_label' => $data['dose_label'],
            'administered_at' => $data['administered_at'],
            'lot' => $data['lot'] ?? null,
            'notes' => $data['notes'] ?? null,
            'reminder_at' => $data['reminder_at'] ?? null,
            'administered_by_user_id' => Auth::id(),
        ]);

        return ImmunizationResource::make($dose)->response()->setStatusCode(201);
    }

    public function destroy(int $id): JsonResponse
    {
        $dose = PatientImmunization::findOrFail($id);
        $dose->delete();

        return response()->json(status: 204);
    }
}
