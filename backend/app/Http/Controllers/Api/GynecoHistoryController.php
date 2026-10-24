<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateGynecoHistoryRequest;
use App\Http\Resources\GynecoHistoryResource;
use App\Models\Patient;
use App\Models\PatientGynecoHistory;
use App\Models\Pregnancy;
use App\Support\AccessLogger;
use Illuminate\Support\Facades\Auth;

/**
 * The patient's gyneco-obstetric history — one row per patient, upserted.
 *
 * Read with `records.view`, written with `records.manage`, NOT with
 * `patients.manage`: the front desk holds `patients.manage`, and sexual debut,
 * number of pregnancies and terminations are not front-desk data. That makes this
 * stricter than `personal_background` is today, which is a separate debt.
 */
class GynecoHistoryController extends Controller
{
    public function show(int $patientId, AccessLogger $accessLogger): GynecoHistoryResource
    {
        $patient = Patient::findOrFail($patientId);

        $accessLogger->log('patient', $patient->id, 'gyneco_history');

        $history = PatientGynecoHistory::query()
            ->where('patient_id', $patient->id)
            ->first()
            // An empty shell rather than a 404: "no history recorded yet" is a
            // normal state of a patient, not a missing resource.
            ?? new PatientGynecoHistory(['patient_id' => $patient->id]);

        return GynecoHistoryResource::make($history)
            ->withRegistered($this->registeredCounts($patient->id));
    }

    public function update(UpdateGynecoHistoryRequest $request, int $patientId): GynecoHistoryResource
    {
        $patient = Patient::findOrFail($patientId);

        $history = PatientGynecoHistory::query()
            ->where('patient_id', $patient->id)
            ->first() ?? new PatientGynecoHistory(['patient_id' => $patient->id]);

        // Explicit column writes, never a whole-object assignment from the
        // request. Writing the object wholesale is precisely the bug this table
        // exists to avoid, and it would be silent.
        $history->fill($request->validated());
        $history->patient_id = $patient->id;
        $history->updated_by_user_id = Auth::id();
        $history->save();

        return GynecoHistoryResource::make($history->fresh())
            ->withRegistered($this->registeredCounts($patient->id));
    }

    /**
     * What THIS system actually witnessed, as opposed to what the patient
     * reported. Never written back over the declared values.
     *
     * @return array<string, int>
     */
    private function registeredCounts(int $patientId): array
    {
        $pregnancies = Pregnancy::query()
            ->where('patient_id', $patientId)
            ->get(['status', 'outcome']);

        $by = fn (string $outcome) => $pregnancies->where('outcome', $outcome)->count();

        return [
            'gestations' => $pregnancies->count(),
            'term_births' => $by('term_birth'),
            'preterm_births' => $by('preterm_birth'),
            'cesareans' => $by('cesarean'),
            'abortions' => $by('abortion'),
            'ectopics' => $by('ectopic'),
            'stillbirths' => $by('stillbirth'),
            'ongoing' => $pregnancies->where('status', 'active')->count(),
        ];
    }
}
