<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ClosePregnancyRequest;
use App\Http\Requests\StorePregnancyRequest;
use App\Http\Requests\StorePregnancyScreeningRequest;
use App\Http\Requests\UpdatePregnancyRequest;
use App\Http\Resources\PregnancyResource;
use App\Http\Resources\PregnancyScreeningResource;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\Pregnancy;
use App\Models\PregnancyScreening;
use App\Services\Obstetrics\GestationalAge;
use App\Services\Obstetrics\PrenatalScreenings;
use App\Support\AccessLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

/**
 * Pregnancies of a patient.
 *
 * Isolation: `Patient` and `Pregnancy` both use `BelongsToWorkspace`, so an id
 * belonging to another clinic resolves to 404 through `findOrFail()` — the same
 * argument that covers `patients/{id}/immunizations` and `immunizations/{id}`.
 */
class PregnancyController extends Controller
{
    public function index(int $patientId, AccessLogger $accessLogger): AnonymousResourceCollection
    {
        $patient = Patient::findOrFail($patientId);

        $accessLogger->log('patient', $patient->id, 'pregnancies');

        $pregnancies = Pregnancy::query()
            ->where('patient_id', $patient->id)
            ->with('screenings')
            // Active first, then most recent: the one being followed is the one
            // the doctor came for.
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderByDesc('lmp_date')
            ->orderByDesc('id')
            ->get();

        return PregnancyResource::collection($pregnancies);
    }

    public function show(int $id, AccessLogger $accessLogger): PregnancyResource
    {
        $pregnancy = Pregnancy::with('screenings')->findOrFail($id);

        $accessLogger->log('patient', $pregnancy->patient_id, 'pregnancy');

        return PregnancyResource::make($pregnancy);
    }

    public function store(StorePregnancyRequest $request, int $patientId): JsonResponse
    {
        $patient = Patient::findOrFail($patientId);

        // The database also refuses a second active pregnancy (partial unique
        // index), which is what makes two open tabs safe. This check exists to
        // answer with something a person can read instead of a 500.
        $open = Pregnancy::query()
            ->where('patient_id', $patient->id)
            ->where('status', 'active')
            ->first();

        if ($open !== null) {
            return response()->json([
                'message' => 'Esta paciente ya tiene un embarazo en curso. Ciérralo antes de abrir otro.',
                'errors' => ['status' => ['Ya hay un embarazo en curso.']],
                'pregnancy_id' => $open->id,
            ], 422);
        }

        $data = $this->withDerivedFields($request->validated());

        $pregnancy = Pregnancy::create([
            ...$data,
            'patient_id' => $patient->id,
            'status' => 'active',
            'opened_by_user_id' => Auth::id(),
        ]);

        return PregnancyResource::make($pregnancy->fresh())->response()->setStatusCode(201);
    }

    public function update(UpdatePregnancyRequest $request, int $id): PregnancyResource
    {
        $pregnancy = Pregnancy::findOrFail($id);

        // Merge over what is already stored: a partial update that only moves
        // `dating_source` still has to re-derive the due date from the dates
        // already on the row, not from an empty payload.
        $merged = array_merge($pregnancy->datingInput(), $request->validated());

        $pregnancy->update($this->withDerivedFields($merged, $request->validated()));

        return PregnancyResource::make($pregnancy->fresh());
    }

    public function close(ClosePregnancyRequest $request, int $id): PregnancyResource
    {
        $pregnancy = Pregnancy::findOrFail($id);

        $data = $request->validated();

        $pregnancy->update([
            'status' => $data['status'] ?? 'completed',
            'outcome' => $data['outcome'],
            'outcome_date' => $data['outcome_date'],
            'outcome_notes' => $data['outcome_notes'] ?? null,
            'closed_by_user_id' => Auth::id(),
        ]);

        return PregnancyResource::make($pregnancy->fresh());
    }

    public function destroy(int $id): JsonResponse
    {
        $pregnancy = Pregnancy::findOrFail($id);

        // A pregnancy with visits attached is history, not a mistake. Deleting it
        // would orphan consultations the doctor wrote; closing it is the correct
        // move, and the `restrictOnDelete` on `medical_records.pregnancy_id` would
        // refuse anyway — better a readable 422 than a foreign-key error.
        if ($pregnancy->medicalRecords()->exists()) {
            return response()->json([
                'message' => 'Este embarazo tiene consultas registradas. Ciérralo con su desenlace en vez de eliminarlo.',
                'errors' => ['pregnancy' => ['Tiene consultas asociadas.']],
            ], 422);
        }

        $pregnancy->delete();

        return response()->json(status: 204);
    }

    /**
     * The pregnancy's follow-up, one point per prenatal visit.
     *
     * Built from the consultations linked by `medical_records.pregnancy_id` — the
     * measurements are read from where they were already written (the prenatal
     * template and the visit's vitals), not from a second table holding the same
     * facts. That is why `pregnancy_controls` was dropped.
     *
     * The gestational age of each point is recomputed from the pregnancy's
     * CURRENT dating, on purpose: if the clinician re-dates by ultrasound, the
     * whole curve moves with the correction instead of keeping points plotted
     * against a week count nobody believes any more.
     *
     * No normal band is drawn. Fundal-height percentiles are a published table
     * (CLAP/OPS), and this product does not draw clinical reference ranges it
     * cannot cite — the same rule that keeps the MSP risk score out until its
     * manual turns up.
     */
    public function timeline(int $id, AccessLogger $accessLogger): JsonResponse
    {
        $pregnancy = Pregnancy::findOrFail($id);

        $accessLogger->log('patient', $pregnancy->patient_id, 'pregnancy_timeline');

        $dating = $pregnancy->datingInput();

        $points = MedicalRecord::query()
            ->where('pregnancy_id', $pregnancy->id)
            ->with('latestVital')
            ->orderBy('created_at')
            ->get(['id', 'created_at', 'structured_exam'])
            ->map(function (MedicalRecord $record) use ($dating) {
                $exam = $record->structured_exam ?? [];
                $age = GestationalAge::at($dating, $record->created_at);

                return [
                    'record_id' => $record->id,
                    'date' => $record->created_at?->toDateString(),
                    'weeks' => $age['weeks'],
                    'label' => $age['label'],
                    // Read as written, then made numeric: the template stores text,
                    // and "32 cm" must plot at 32 rather than not plot at all.
                    'fundal_height' => self::numeric($exam['altura_uterina'] ?? null),
                    'fetal_heart_rate' => self::numeric($exam['fcf'] ?? null),
                    'weight' => self::numeric($record->latestVital?->weight_kg),
                    // Integers in the vitals table; a control without them is a gap
                    // in the line, never a zero.
                    'bp_systolic' => $record->latestVital?->blood_pressure_sys,
                    'bp_diastolic' => $record->latestVital?->blood_pressure_dia,
                    'presentation' => $exam['presentacion'] ?? null,
                ];
            })
            // A visit nobody could date is a point with no x: it belongs in the
            // history, not on a curve.
            ->filter(fn (array $p) => $p['weeks'] !== null)
            ->values();

        return response()->json(['data' => $points]);
    }

    /** First number in a free-text measurement, or null. */
    private static function numeric(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/-?\d+(?:[.,]\d+)?/', (string) $value, $m)
            ? (float) str_replace(',', '.', $m[0])
            : null;
    }

    /**
     * The MSP prenatal screening schedule, with whatever is already recorded for
     * this pregnancy merged in.
     *
     * Returns the WHOLE catalogue, not just the rows that exist, because the
     * point of a perinatal card is seeing what is still missing. And every entry
     * says whether the guideline asks for it universally or only on a risk
     * factor — without that, an empty box reads as an omission when it is not.
     */
    public function screenings(int $id): JsonResponse
    {
        $pregnancy = Pregnancy::with('screenings')->findOrFail($id);

        $recorded = $pregnancy->screenings->keyBy('code');

        $items = collect(PrenatalScreenings::all())->map(function (array $meta, string $code) use ($recorded) {
            // ->get(), not [$code]: a Collection's offsetGet THROWS on a missing
            // key rather than returning null, so `?? null` does not save you —
            // and most of this catalogue is missing most of the time, which is
            // precisely the point of showing it.
            $row = $recorded->get($code);

            return [
                'code' => $code,
                'label' => $meta['label'],
                'when' => $meta['when'],
                'universal' => $meta['universal'],
                'result' => $row?->result,
                'result_at' => $row?->result_at?->toDateString(),
                'notes' => $row?->notes,
                'recorded' => $row !== null,
            ];
        })->values();

        return response()->json([
            'data' => $items,
            'meta' => ['source' => PrenatalScreenings::SOURCE],
        ]);
    }

    /** Record or correct one screening. Upsert by (pregnancy, code). */
    public function storeScreening(StorePregnancyScreeningRequest $request, int $id): JsonResponse
    {
        $pregnancy = Pregnancy::findOrFail($id);

        $data = $request->validated();

        $screening = PregnancyScreening::updateOrCreate(
            ['pregnancy_id' => $pregnancy->id, 'code' => $data['code']],
            [
                'result' => $data['result'] ?? null,
                'result_at' => $data['result_at'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by_user_id' => Auth::id(),
            ]
        );

        return PregnancyScreeningResource::make($screening)->response()->setStatusCode(201);
    }

    /**
     * The single place the due date is written.
     *
     * What actually stops a client from writing its own due date is the FormRequest
     * allow-list: `edd_date` and `edd_protocol` are not among the rules, so
     * `validated()` never carries them. This `unset()` is the second line, for the
     * day someone widens those rules or reaches for `$request->all()` — it is
     * deliberately unreachable today, and `PregnancyLifecycleTest` says so.
     *
     * The guarantee itself is not decorative: a stale bundle must never be able to
     * write a wrong due date into a clinical record, the same rule
     * `MedicalRecordController::withDerivedFields()` applies to the Score MAMÁ.
     *
     * @param  array<string, mixed>  $dating  the full dating picture to compute from
     * @param  array<string, mixed>|null  $writable  what should actually be written
     * @return array<string, mixed>
     */
    private function withDerivedFields(array $dating, ?array $writable = null): array
    {
        $writable ??= $dating;

        unset($writable['edd_date'], $writable['edd_protocol']);

        return array_merge($writable, GestationalAge::columnsFor($dating));
    }
}
