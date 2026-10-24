<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Patient;
use App\Models\WorkspaceMember;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use App\Support\PatientSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Cashier (caja) endpoints. Both run under the `workspace` middleware so
 * WorkspaceScope isolates the data, and both emit a deliberately MINIMAL payload
 * — billing never receives clinical data (LODP). The cashier cobra from the
 * "por cobrar" list (completed appointments without an invoice) and, for
 * walk-ins, finds a patient via a name/cédula lookup limited to billing fields.
 */
class BillingController extends Controller
{
    /**
     * "Por cobrar": completed appointments in the active workspace that still
     * have no invoice. Reuses Appointment::invoice() (hasOne) for the filter.
     * An employed doctor (clinical-only member) only sees their own attentions
     * — the rest of the clinic's pending collections are not theirs to read.
     */
    public function billables(
        WorkspacePermissionService $permissions,
        ActiveWorkspace $activeWorkspace,
    ): JsonResponse {
        $ownDoctorId = $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current());

        $appointments = Appointment::query()
            ->with(['patient', 'doctor', 'medicalRecords.services'])
            ->where('status', 'completed')
            ->whereDoesntHave('invoice')
            ->when($ownDoctorId, fn ($q, $d) => $q->where('doctor_id', $d))
            ->orderBy('appointment_date')
            ->get();

        return response()->json([
            'data' => $appointments->map(fn (Appointment $a) => [
                'appointment_id' => $a->id,
                // Wall-clock, no offset (see AppointmentResource).
                'date' => $a->appointment_date?->format('Y-m-d\TH:i:s'),
                'patient' => $a->patient ? [
                    'id' => $a->patient->id,
                    'name' => $a->patient->name,
                    'id_number' => $a->patient->id_number,
                ] : null,
                'doctor' => $a->doctor ? ['id' => $a->doctor->id, 'name' => $a->doctor->name] : null,
                // What the doctor declared as performed, so the invoice is not
                // rebuilt from memory. This is the ONLY way those services reach
                // the cash desk: reception and billing hold no records.view, and
                // must not — they get what to charge, never the clinical record.
                'services' => $a->medicalRecords->flatMap->services->map(fn ($s) => [
                    'medical_service_id' => $s->medical_service_id,
                    'name' => $s->name,
                    'category' => $s->category,
                    'quantity' => (string) $s->quantity,
                    'unit_price' => $s->unit_price === null ? null : (string) $s->unit_price,
                ])->values(),
            ])->values(),
        ]);
    }

    /**
     * Doctors billable in the active workspace, for the cashier's "médico
     * atendiente" picker. Returns {id, name, commission_pct} — the default
     * revenue-share the cashier can override per attention. Reads the
     * authoritative workspace_members roster (kept in sync with clinic_doctor).
     */
    public function doctors(ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $members = WorkspaceMember::query()
            ->where('workspace_id', $activeWorkspace->id())
            ->whereNotNull('doctor_id')
            ->where('status', 'active')
            ->with('doctor:id,name')
            ->get();

        $doctors = $members
            ->filter(fn (WorkspaceMember $m) => $m->doctor !== null)
            ->map(fn (WorkspaceMember $m) => [
                'id' => $m->doctor->id,
                'name' => $m->doctor->name,
                'commission_pct' => $m->commission_pct !== null ? (string) $m->commission_pct : null,
            ])
            ->unique('id')
            ->sortBy('name')
            ->values();

        return response()->json(['data' => $doctors]);
    }

    /**
     * Billing-scoped patient lookup for walk-ins. Returns ONLY {id, name,
     * id_number} — never allergies, history or full demographics.
     */
    public function patientLookup(Request $request): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['data' => []]);
        }

        $patients = PatientSearch::apply(Patient::query(), $term)
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'id_number']);

        return response()->json([
            'data' => $patients->map(fn (Patient $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'id_number' => $p->id_number,
            ])->values(),
        ]);
    }
}
