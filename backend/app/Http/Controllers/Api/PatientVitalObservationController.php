<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePatientVitalObservationRequest;
use App\Http\Resources\PatientVitalObservationResource;
use App\Models\Patient;
use App\Services\PatientVitalObservationService;
use Illuminate\Http\JsonResponse;

class PatientVitalObservationController extends Controller
{
    public function store(
        StorePatientVitalObservationRequest $request,
        int $id,
        PatientVitalObservationService $observations,
    ): JsonResponse {
        $patient = Patient::findOrFail($id);
        $observation = $observations->record($patient, $request->user(), $request->validated());

        return PatientVitalObservationResource::make($observation)->response()->setStatusCode(201);
    }
}
