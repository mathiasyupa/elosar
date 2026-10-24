<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientAlertRequest;
use App\Http\Resources\PatientAlertResource;
use App\Models\Patient;
use App\Models\PatientAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * Standing warnings about a patient, shown wherever she is on screen.
 *
 * An alert is resolved, never deleted: "this stopped applying on such a date"
 * is clinical information, and a warning that can be made to vanish is a warning
 * nobody can audit. `destroy` exists only for the one written by mistake.
 */
class PatientAlertController extends Controller
{
    public function index(int $patientId): AnonymousResourceCollection
    {
        $patient = Patient::findOrFail($patientId);

        $alerts = PatientAlert::query()
            ->where('patient_id', $patient->id)
            // Active first, and inside each group the newest on top.
            ->orderByRaw('resolved_at IS NOT NULL')
            ->orderByDesc('id')
            ->get();

        return PatientAlertResource::collection($alerts);
    }

    public function store(StorePatientAlertRequest $request, int $patientId): JsonResponse
    {
        $patient = Patient::findOrFail($patientId);

        $alert = PatientAlert::create([
            ...$request->validated(),
            'patient_id' => $patient->id,
            'created_by_user_id' => Auth::id(),
        ]);

        return PatientAlertResource::make($alert)->response()->setStatusCode(201);
    }

    /** Closing an alert keeps it: who closed it and when is part of the record. */
    public function resolve(int $id): PatientAlertResource
    {
        $alert = PatientAlert::findOrFail($id);

        $alert->update([
            'resolved_at' => now(),
            'resolved_by_user_id' => Auth::id(),
        ]);

        return PatientAlertResource::make($alert->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $alert = PatientAlert::findOrFail($id);
        $alert->delete();

        return response()->json(status: 204);
    }
}
