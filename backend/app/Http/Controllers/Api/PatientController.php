<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\PurgeAllPatientsRequest;
use App\Http\Requests\StoreAllergyRequest;
use App\Http\Requests\StorePatientRequest;
use App\Http\Requests\UpdatePatientRequest;
use App\Http\Resources\MedicalRecordResource;
use App\Http\Resources\PatientResource;
use App\Models\Appointment;
use App\Models\FamilyBackground;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\PatientVitalObservation;
use App\Models\Payment;
use App\Models\RecordVital;
use App\Models\Specialty;
use App\Notifications\PatientGreetingNotification;
use App\Services\PatientDeletionService;
use App\Services\WorkspacePermissionService;
use App\Support\AccessLogger;
use App\Support\ActiveWorkspace;
use App\Support\PatientSearch;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Patients of the active workspace. Patients are shared across the doctors of a
 * clinic, so isolation is handled entirely by WorkspaceScope — no per-doctor
 * filter here. Allergies and family background have no scope of their own and
 * are always reached through the workspace-scoped parent patient.
 */
class PatientController extends Controller
{
    /**
     * Paginated patient list with optional `?q=` search by name or id number.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'attended_from' => ['nullable', 'date_format:Y-m-d'],
            'attended_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:attended_from'],
        ]);

        $query = Patient::query()
            // Most recent consultation date — powers "last visit" and the
            // "recent patients" picker. The subquery inherits MedicalRecord's
            // WorkspaceScope, so it stays workspace-isolated.
            ->withMax('medicalRecords', 'created_at');

        if ($term = trim((string) $request->query('q'))) {
            PatientSearch::apply($query, $term);
        }

        // `?has_insurance=1` narrows to patients with a recorded insurer (the
        // Seguros screen). Paginated server-side, not filtered on page 1.
        if ($request->boolean('has_insurance')) {
            $query->whereNotNull('insurance_provider')
                ->where('insurance_provider', '!=', '');
        }

        // A patient counts as attended only once their clinical consultation is
        // completed. This includes walk-ins because closing their consultation
        // creates the same completed record as an appointment-based visit.
        if ($filters['attended_from'] ?? $filters['attended_to'] ?? null) {
            $query->whereHas('medicalRecords', function ($records) use ($filters): void {
                $records->where('status', 'completed')
                    ->when(
                        $filters['attended_from'] ?? null,
                        fn ($records, string $from) => $records->whereDate('created_at', '>=', $from),
                    )
                    ->when(
                        $filters['attended_to'] ?? null,
                        fn ($records, string $to) => $records->whereDate('created_at', '<=', $to),
                    );
            });
        }

        // `?sort=recent` lists by most recent visit first (picker default view);
        // otherwise chronological by registration, newest first — the directory
        // is a log of who joined the practice, not a phone book.
        if ($request->query('sort') === 'recent') {
            $query->orderByRaw('medical_records_max_created_at DESC NULLS LAST')->orderBy('name');
        } else {
            $query->orderByDesc('created_at');
        }

        return PatientResource::collection(
            $query->paginate($request->integer('per_page', 20))
        );
    }

    /**
     * Patient milestones for the "Felicita a tu paciente" section: birthdays
     * (today + the coming week), patient anniversaries (years since the first
     * consultation), recently welcomed patients, and patients to re-engage
     * (no visit in 6+ months). Each item carries the phone so the UI can build
     * a WhatsApp click-to-chat link. WorkspaceScope keeps it tenant-isolated.
     *
     * The re-engage list is a CALL LIST, so it is built to be worked top to
     * bottom: patients who already have an appointment on the books are left
     * out (nothing more awkward than inviting back someone who comes Tuesday),
     * and the order is by how much the patient has actually left at the
     * practice rather than by how stale they are — a regular of two years who
     * stopped seven months ago is worth far more than a single visit from 2019.
     */
    public function celebrations(WorkspacePermissionService $permissions): JsonResponse
    {
        $now = now();
        $todayMd = $now->format('m-d');
        $weekMds = collect(range(1, 7))->map(fn ($d) => $now->copy()->addDays($d)->format('m-d'))->all();

        // Already workspace-scoped by BelongsToWorkspace — this is one clinic's
        // patients, not the whole table. Still trimmed to the columns the loop
        // below actually reads, instead of `SELECT *`, since the birthday/
        // anniversary/reactivation windows below span the full roster and
        // cannot be pushed into a WHERE without risking the "next 7 days"
        // month-boundary math (Jan 28 → Feb) with no test coverage today.
        $patients = Patient::query()
            ->select(['id', 'name', 'phone', 'email', 'birth_date', 'created_at'])
            ->withMin('medicalRecords', 'created_at')
            ->withMax('medicalRecords', 'created_at')
            ->withCount('medicalRecords')
            ->get();

        // Patients with an appointment still ahead of them: they need no
        // invitation, so they never reach the re-engage list.
        $booked = Appointment::query()
            ->whereDate('appointment_date', '>=', $now->toDateString())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->pluck('patient_id')
            ->flip();

        // Lifetime collected per patient, used to rank the call list. Only
        // surfaced to members who may see money (a nurse holds patients.view
        // but no billing.view), though the ranking itself always applies.
        $canSeeMoney = $permissions->can(Auth::user(), app(ActiveWorkspace::class)->current(), 'billing.view');

        $spentByPatient = Payment::query()
            ->join('invoices', 'payments.invoice_id', '=', 'invoices.id')
            ->selectRaw('invoices.patient_id AS pid, SUM(payments.amount) AS total')
            ->groupBy('invoices.patient_id')
            ->pluck('total', 'pid');

        $birthdaysToday = [];
        $birthdaysWeek = [];
        $anniversaries = [];
        $newPatients = [];
        $reactivate = [];

        foreach ($patients as $p) {
            $base = [
                'id' => $p->id,
                'name' => $p->name,
                'phone' => $p->phone,
                'email' => $p->email,
                'birth_date' => $p->birth_date?->toDateString(),
                'age' => $p->birth_date?->age,
            ];

            // Birthdays — today takes precedence over the upcoming-week bucket.
            if ($p->birth_date) {
                $bMd = $p->birth_date->format('m-d');
                if ($bMd === $todayMd) {
                    $birthdaysToday[] = $base;
                } elseif (in_array($bMd, $weekMds, true)) {
                    $birthdaysWeek[] = $base + ['birthday_md' => $bMd];
                }
            }

            $first = $p->medical_records_min_created_at ? Carbon::parse($p->medical_records_min_created_at) : null;
            $last = $p->medical_records_max_created_at ? Carbon::parse($p->medical_records_max_created_at) : null;

            // Patient anniversary: first consultation falls on today's date in a past year.
            if ($first && $first->format('m-d') === $todayMd && $first->year < $now->year) {
                $anniversaries[] = $base + ['years' => $now->year - $first->year, 'first_visit_at' => $first->toDateString()];
            }

            // Imports do not create a clinical consultation. Recently added
            // patients must therefore use their registration timestamp.
            if ($p->created_at && $p->created_at->gte($now->copy()->subDays(30))) {
                $newPatients[] = $base + ['registered_at' => $p->created_at->toDateString()];
            }

            // Re-engage: no visit in 6+ months AND nothing already scheduled.
            if ($last && $last->lt($now->copy()->subMonths(6)) && ! $booked->has($p->id)) {
                $spent = bcadd((string) ($spentByPatient[$p->id] ?? '0'), '0', 2);

                $reactivate[] = $base + [
                    'last_visit_at' => $last->toDateString(),
                    'months_since' => (int) $last->diffInMonths($now),
                    'visits' => (int) $p->medical_records_count,
                    'spent' => $canSeeMoney ? $spent : null,
                    '_rank' => (float) $spent,
                ];
            }
        }

        // Worth-most first, then the most overdue among equals; cap each group
        // to keep the payload small.
        usort($reactivate, fn ($a, $b) => [$b['_rank'], $b['months_since']] <=> [$a['_rank'], $a['months_since']]);
        $reactivate = array_map(function (array $row) {
            unset($row['_rank']);

            return $row;
        }, $reactivate);

        return response()->json([
            'data' => [
                'birthdays_today' => array_slice($birthdaysToday, 0, 100),
                'birthdays_week' => array_slice($birthdaysWeek, 0, 100),
                'anniversaries_today' => array_slice($anniversaries, 0, 100),
                'new_patients' => array_slice($newPatients, 0, 100),
                'reactivate' => array_slice($reactivate, 0, 50),
            ],
        ]);
    }

    /**
     * Follow-ups the doctor ordered that never turned into an appointment
     * ("control en 3 meses" that nobody booked).
     *
     * A record's follow_up_date is the clinical instruction; this returns the
     * ones that are due (or about to be) where the patient has NOT come back
     * since and has nothing scheduled. Only the latest follow-up per patient is
     * kept, so a long-standing patient appears once.
     *
     * Gated by patients.view because working the list is front-desk work, but
     * the clinical note (follow_up_notes) is only attached for members who may
     * read records — reception can call and schedule without being told why
     * (LOPDP). An employed doctor sees only the follow-ups they ordered.
     */
    public function pendingFollowUps(
        Request $request,
        WorkspacePermissionService $permissions,
        ActiveWorkspace $activeWorkspace,
    ): JsonResponse {
        $today = Carbon::today();
        // Overdue for up to a year back, plus the ones landing in the next month.
        $from = $today->copy()->subYear();
        $to = $today->copy()->addDays($request->integer('ahead', 30));

        $ownDoctorId = $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current());
        $canSeeNotes = $permissions->can(Auth::user(), $activeWorkspace->current(), 'records.view');

        $booked = Appointment::query()
            ->whereDate('appointment_date', '>=', $today->toDateString())
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->pluck('patient_id')
            ->flip();

        $records = MedicalRecord::query()
            ->whereNotNull('follow_up_date')
            ->whereBetween('follow_up_date', [$from->toDateString(), $to->toDateString()])
            ->when($ownDoctorId, fn ($q, $d) => $q->where('created_by', $d))
            ->with(['patient:id,name,id_number,phone,email', 'doctor:id,name'])
            ->orderByDesc('follow_up_date')
            ->get();

        $seen = [];
        $candidates = [];

        foreach ($records as $record) {
            // Records are date-desc, so the first one seen is the latest order.
            if ($record->patient_id === null || isset($seen[$record->patient_id])) {
                continue;
            }
            $seen[$record->patient_id] = true;

            if ($booked->has($record->patient_id)) {
                continue; // already coming back
            }

            $candidates[$record->patient_id] = $record;
        }

        // One query for every candidate instead of one per candidate: every
        // OTHER record's date for these patients, fetched once and grouped in
        // PHP. The consultation that ORDERED the control is never evidence of
        // a return, so its own id is excluded (a control can be dated before
        // the record that ordered it, e.g. when backdated).
        $laterVisitDatesByPatient = MedicalRecord::query()
            ->whereIn('patient_id', array_keys($candidates))
            ->whereNotIn('id', array_map(fn ($r) => $r->id, $candidates))
            ->get(['patient_id', 'created_at'])
            ->groupBy('patient_id');

        $items = [];

        foreach ($candidates as $patientId => $record) {
            $dueAt = $record->follow_up_date;

            // Came back on their own after the follow-up was due → resolved.
            $returned = ($laterVisitDatesByPatient->get($patientId) ?? collect())
                ->contains(fn ($r) => $r->created_at->toDateString() >= $dueAt->toDateString());

            if ($returned) {
                continue;
            }

            $items[] = [
                'record_id' => $record->id,
                'patient_id' => $record->patient_id,
                'name' => $record->patient?->name,
                'id_number' => $record->patient?->id_number,
                'phone' => $record->patient?->phone,
                'email' => $record->patient?->email,
                'doctor' => $record->doctor?->name,
                'follow_up_date' => $dueAt->toDateString(),
                'notes' => $canSeeNotes ? $record->follow_up_notes : null,
                'overdue' => $dueAt->lt($today),
                'days' => (int) $today->diffInDays($dueAt, false),
            ];
        }

        // Most overdue first — the ones slipping away the longest.
        usort($items, fn ($a, $b) => $a['follow_up_date'] <=> $b['follow_up_date']);

        return response()->json([
            'data' => array_slice($items, 0, 100),
            'meta' => ['overdue' => count(array_filter($items, fn ($i) => $i['overdue']))],
        ]);
    }

    /**
     * Email a relationship greeting (birthday / anniversary / welcome /
     * re-engagement) to a patient. Sent by the system, Reply-To the authenticated
     * doctor. Mirrors the WhatsApp greeting from the "Felicita a tu paciente" UI.
     */
    public function greetingEmail(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', PatientGreetingNotification::TYPES)],
            'years' => ['nullable', 'integer', 'min:1'],
        ]);

        $patient = Patient::findOrFail($id);
        if (! $patient->email) {
            throw ValidationException::withMessages(['email' => 'El paciente no tiene un correo registrado.']);
        }

        // Now, not queued: the queue worker rebuilds the Patient with no workspace
        // bound and the tenant guard failed every greeting (as it did reminders).
        $patient->notifyNow(new PatientGreetingNotification(
            type: $data['type'],
            doctor: Auth::user()->doctor,
            years: $data['years'] ?? null,
            workspaceName: app(ActiveWorkspace::class)->current()?->name,
        ));

        return response()->json(['sent' => true]);
    }

    public function show(
        int $id,
        ActiveWorkspace $activeWorkspace,
        WorkspacePermissionService $permissions,
    ): PatientResource {
        $user = Auth::user();
        $workspace = $activeWorkspace->current();
        $relations = ['allergies', 'familyBackground', 'alerts'];

        // A pregnancy carries FUM, ultrasound dating, due date and risk notes.
        // Being a qualified doctor is not enough in a clinic where this member
        // is operationally assigned to reception or administration: the usual
        // clinical-record permission remains the second half of the gate.
        if (
            $workspace
            && $user?->doctor?->hasSpecialtyCapability(Specialty::CAPABILITY_GYNECO_OBSTETRICS)
            && $permissions->can($user, $workspace, 'records.view')
        ) {
            $relations[] = 'pregnancies';
        }

        $patient = Patient::with($relations)
            ->withMax('medicalRecords', 'created_at')
            ->findOrFail($id);

        return PatientResource::detailed($patient);
    }

    public function store(StorePatientRequest $request): JsonResponse
    {
        // workspace_id is auto-filled by BelongsToWorkspace from the active workspace.
        $data = $this->withConsent($request->validated());

        try {
            $patient = Patient::create($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'id_number' => ['Ya existe un paciente con ese documento.'],
            ]);
        }

        return PatientResource::detailed($patient->load(['allergies', 'familyBackground', 'pregnancies', 'alerts']))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdatePatientRequest $request, int $id): PatientResource
    {
        $patient = Patient::findOrFail($id);
        $data = $this->withConsent($request->validated());

        try {
            $patient->update($data);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'id_number' => ['Ya existe un paciente con ese documento.'],
            ]);
        }

        return PatientResource::detailed($patient->load(['allergies', 'familyBackground', 'pregnancies', 'alerts']));
    }

    /**
     * Tell the caller what deleting these patients would actually do, before it
     * does it.
     *
     * Exists as its own endpoint rather than as a field on the patient list so
     * the retention EXISTS subqueries are paid once, when someone is actually about to
     * delete, instead of on every page of every patient list forever.
     */
    public function deletionPreview(Request $request, PatientDeletionService $deletions): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        return response()->json($deletions->preview($data['ids']));
    }

    /**
     * Count the complete active directory before its owner can empty it. This
     * deliberately ignores the current list page, search and date filters.
     */
    public function purgePreview(PatientDeletionService $deletions): JsonResponse
    {
        return response()->json(['data' => $deletions->previewPurge()]);
    }

    /**
     * Empty a newly-created patient directory. This is owner-only and all or
     * nothing: any retention trace or stale confirmation leaves every row as
     * it was, rather than producing an import-blocking archived subset.
     */
    public function purge(PurgeAllPatientsRequest $request, PatientDeletionService $deletions): JsonResponse
    {
        $result = $deletions->purgeAll($request->validated('challenge'));

        if ($result['state'] !== 'purged') {
            $message = $result['state'] === 'blocked'
                ? 'No se eliminó ningún paciente porque el directorio contiene información que debe conservarse.'
                : 'El directorio cambió o la confirmación venció. Revísalo antes de volver a intentarlo.';

            return response()->json([
                'message' => $message,
                'data' => [
                    'total' => $result['total'],
                    'deletable' => $result['deletable'],
                    'blocked' => $result['blocked'],
                ],
            ], 409);
        }

        return response()->json([
            'data' => [
                'deleted' => $result['deleted'],
            ],
        ]);
    }

    /**
     * Remove a patient from the workspace — and what that means depends on the
     * patient, not on who asks.
     *
     * A row with nothing attached is a mistake someone typed: a duplicate, a
     * test, a mistyped cédula. Deleting it for real is the honest outcome;
     * keeping it forever would only grow a list nobody wants.
     *
     * A patient with any trace of care or billing is ARCHIVED instead. That
     * history carries a retention duty that belongs to the professional, and no
     * button in a list view should be able to destroy it. It leaves the list; it
     * does not leave the database. The caller is told which of the two happened,
     * because "eliminado" and "archivado" are not the same promise.
     */
    public function destroy(int $id, PatientDeletionService $deletions): JsonResponse
    {
        return response()->json($deletions->remove($id));
    }

    /**
     * Translate the LOPDP `data_consent` boolean into the stamped audit fields
     * (timestamp, text version, capturing user), so consent metadata is always
     * set server-side and never spoofable from the request body.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withConsent(array $data): array
    {
        $consented = (bool) ($data['data_consent'] ?? false);
        unset($data['data_consent']);

        if ($consented) {
            $data['data_consent_at'] = now();
            $data['data_consent_version'] = config('lopdp.consent_version');
            $data['data_consent_by_user_id'] = Auth::id();
        }

        return $data;
    }

    /** Latest vital signs, including standalone nursing observations. */
    public function baselineVitals(int $id, AccessLogger $accessLogger): JsonResponse
    {
        Patient::findOrFail($id);

        $vital = RecordVital::query()
            ->whereHas('medicalRecord', fn ($q) => $q->where('patient_id', $id))
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        $observation = PatientVitalObservation::query()
            ->where('patient_id', $id)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();

        $accessLogger->log('patient_vitals', $id);

        if ($observation && (! $vital || $observation->recorded_at->greaterThan($vital->recorded_at))) {
            return response()->json([
                'data' => [
                    'source' => 'standalone',
                    'recorded_at' => $observation->recorded_at?->toIso8601String(),
                    'weight_kg' => $observation->weight_kg,
                    'height_cm' => $observation->height_cm,
                    'head_circumference_cm' => $observation->head_circumference_cm,
                    'blood_pressure_sys' => $observation->blood_pressure_sys,
                    'blood_pressure_dia' => $observation->blood_pressure_dia,
                    'heart_rate' => $observation->heart_rate,
                    'temperature' => $observation->temperature,
                    'oxygen_saturation' => $observation->oxygen_saturation,
                    'respiratory_rate' => $observation->respiratory_rate,
                ],
            ]);
        }

        if (! $vital) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'source' => 'last_visit',
                'recorded_at' => $vital->recorded_at?->toIso8601String(),
                'weight_kg' => $vital->weight_kg,
                'height_cm' => $vital->height_cm,
                'head_circumference_cm' => $vital->head_circumference_cm,
                'blood_pressure_sys' => $vital->blood_pressure_sys,
                'blood_pressure_dia' => $vital->blood_pressure_dia,
                'heart_rate' => $vital->heart_rate,
                'temperature' => $vital->temperature,
                'oxygen_saturation' => $vital->oxygen_saturation,
                'respiratory_rate' => $vital->respiratory_rate,
            ],
        ]);
    }

    /**
     * Clinical history for a patient (most recent first).
     */
    public function records(int $id): AnonymousResourceCollection
    {
        // Ensures the patient exists within the active workspace before listing.
        Patient::findOrFail($id);

        // Vitals and services ride along so the timeline can show what actually
        // happened in a past visit. Until this, nothing in the app read a closed
        // consultation back: the exam, the plan and the alarm signs were written
        // and never displayed again.
        $records = MedicalRecord::query()
            ->where('patient_id', $id)
            ->with(['diagnoses', 'prescriptions', 'vitals', 'growthAssessments', 'services'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return MedicalRecordResource::collection($records);
    }

    /**
     * Add an allergy to a patient via the scoped parent relationship.
     */
    public function storeAllergy(StoreAllergyRequest $request, int $id): JsonResponse
    {
        $patient = Patient::findOrFail($id);

        $allergy = $patient->allergies()->create($request->validated());

        return response()->json([
            'id' => $allergy->id,
            'allergen_type' => $allergy->allergen_type,
            'allergen_name' => $allergy->allergen_name,
            'severity' => $allergy->severity,
            'notes' => $allergy->notes,
        ], 201);
    }

    /**
     * Remove an allergy. Scoped through the parent patient so a foreign allergy
     * id resolves to 404.
     */
    public function destroyAllergy(int $id, int $allergyId): JsonResponse
    {
        $patient = Patient::findOrFail($id);
        $patient->allergies()->findOrFail($allergyId)->delete();

        return response()->json(status: 204);
    }

    /**
     * Add a family-history entry via the scoped parent relationship.
     */
    public function storeFamilyBackground(Request $request, int $id): JsonResponse
    {
        $patient = Patient::findOrFail($id);

        $data = $request->validate([
            'relative_type' => ['required', Rule::in(array_keys(FamilyBackground::RELATIVE_TYPES))],
            'disease' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $entry = $patient->familyBackground()->create($data);

        return response()->json([
            'id' => $entry->id,
            'relative_type' => $entry->relative_type,
            'disease' => $entry->disease,
            'notes' => $entry->notes,
        ], 201);
    }

    public function destroyFamilyBackground(int $id, int $backgroundId): JsonResponse
    {
        $patient = Patient::findOrFail($id);
        $patient->familyBackground()->findOrFail($backgroundId)->delete();

        return response()->json(status: 204);
    }

    public static function removeAccents(string $string): string
    {
        $chars = [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u',
            'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u',
            'ñ' => 'n', 'Ñ' => 'n',
        ];

        return strtr($string, $chars);
    }
}
