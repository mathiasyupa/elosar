<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\InvoiceResource;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Role-aware dashboard. A clinic workspace returns an OPERATIONS board (day
 * queue pipeline + team on duty + alerts); a personal workspace keeps the solo
 * doctor KPIs. Every query is WorkspaceScope-isolated; money is emitted as
 * strings (never floats). Sections within the clinic payload are further gated
 * by the caller's permissions so e.g. billing never receives the clinical queue.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ActiveWorkspace $activeWorkspace,
        private readonly WorkspacePermissionService $permissions,
    ) {}

    public function stats(): JsonResponse
    {
        $workspace = $this->activeWorkspace->current();

        if ($workspace && $workspace->isClinic()) {
            return $this->clinicStats();
        }

        return $this->personalStats();
    }

    /**
     * Operations board for a clinic. Each section is permission-gated.
     */
    private function clinicStats(): JsonResponse
    {
        $user = Auth::user();
        $workspace = $this->activeWorkspace->current();
        $today = Carbon::today();

        $can = fn (string $perm): bool => $this->permissions->can($user, $workspace, $perm);

        $appointments = Appointment::query()
            ->with([
                'doctor',
                'patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')->withCount('allergies'),
                'medicalRecords' => fn ($q) => $q->whereIn('status', ['draft', 'vitals_done']),
            ])
            ->whereDate('appointment_date', $today->toDateString())
            ->orderBy('appointment_date')
            ->get();

        $byStatus = fn (string ...$statuses) => $appointments->whereIn('status', $statuses)->count();

        $payload = [
            'mode' => 'clinic',
            'stats' => [
                'waiting' => $byStatus('checked_in', 'vitals_done', 'with_doctor'),
                'pending_vitals' => $byStatus('checked_in'),
                'ready' => $byStatus('vitals_done'),
                'with_doctor' => $byStatus('with_doctor'),
                'completed' => $byStatus('completed'),
                'no_show' => $byStatus('no_show'),
                'scheduled' => $byStatus('scheduled'),
                'total' => $appointments->count(),
            ],
        ];

        // Day queue (clinical roster) — only for roles that can see appointments.
        if ($can('appointments.view')) {
            $payload['queue'] = $appointments->map(fn (Appointment $a) => [
                'id' => $a->id,
                // Wall-clock, no offset (see AppointmentResource).
                'time' => $a->appointment_date?->format('Y-m-d\TH:i:s'),
                'status' => $a->status,
                'checked_in_at' => $a->checked_in_at?->toIso8601String(),
                'draft_record_id' => $a->medicalRecords->first()?->id,
                'doctor' => $a->doctor ? ['id' => $a->doctor->id, 'name' => $a->doctor->name] : null,
                'patient' => $a->patient ? [
                    'id' => $a->patient->id,
                    'name' => $a->patient->name,
                    'id_number' => $a->patient->id_number,
                    'is_first_visit' => $a->patient->medical_records_max_created_at === null,
                    'has_allergies' => (int) ($a->patient->allergies_count ?? 0) > 0,
                ] : null,
            ])->values();

            // Team on duty: doctors with an appointment today + their counts.
            $payload['team_today'] = $appointments
                ->whereNotNull('doctor')
                ->groupBy(fn (Appointment $a) => $a->doctor->id)
                ->map(fn ($group) => [
                    'doctor_id' => $group->first()->doctor->id,
                    'name' => $group->first()->doctor->name,
                    'appointments' => $group->count(),
                    'completed' => $group->where('status', 'completed')->count(),
                ])
                ->values();

            // Alerts: patients in the queue with recorded allergies.
            $payload['alerts'] = [
                'allergy_patients' => $appointments
                    ->filter(fn (Appointment $a) => $a->patient && (int) ($a->patient->allergies_count ?? 0) > 0
                        && in_array($a->status, ['checked_in', 'vitals_done', 'with_doctor'], true))
                    ->map(fn (Appointment $a) => ['id' => $a->patient->id, 'name' => $a->patient->name])
                    ->values(),
            ];
        }

        // Financial snapshot — billing/owner only. An employed doctor
        // (clinical-only member) gets ONLY their own figures, never the
        // clinic's totals (same self-scoping as /reports/revenue).
        if ($can('billing.view')) {
            $ownDoctorId = $this->permissions->selfScopedDoctorId($user, $workspace);
            $payload['billing'] = $this->billingSnapshot($ownDoctorId)
                + ['scope' => $ownDoctorId ? 'own' : 'clinic'];
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * Solo-doctor KPIs (unchanged behaviour for personal workspaces).
     */
    private function personalStats(): JsonResponse
    {
        $today = Carbon::today();

        $todaysAppointments = Appointment::query()
            ->with(['patient' => fn ($q) => $q->withMax('medicalRecords', 'created_at')])
            ->whereDate('appointment_date', $today->toDateString())
            ->orderBy('appointment_date')
            ->get();

        $recentInvoices = Invoice::query()
            ->with('patient')
            ->latest('issued_at')
            ->latest('id')
            ->limit(5)
            ->get();

        $billing = $this->billingSnapshot();

        return response()->json([
            'data' => [
                'mode' => 'personal',
                'appointments_today' => $todaysAppointments->count(),
                'appointments_pending' => $todaysAppointments->where('status', 'scheduled')->count(),
                'appointments_completed' => $todaysAppointments->where('status', 'completed')->count(),
                'patients_total' => Patient::query()->count(),
                'month_revenue' => $billing['month_revenue'],
                'last_month_revenue' => $billing['last_month_revenue'],
                'pending_collections' => $billing['pending_collections'],

                'todays_appointments' => AppointmentResource::collection($todaysAppointments),
                'recent_invoices' => InvoiceResource::collection($recentInvoices),
            ],
        ]);
    }

    /**
     * Shared billing KPIs (bcmath strings). When $ownDoctorId is given, every
     * figure is restricted to invoices attended by that doctor.
     *
     * @return array{month_revenue: string, last_month_revenue: string, pending_collections: string}
     */
    private function billingSnapshot(?int $ownDoctorId = null): array
    {
        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth();
        $prevStart = $today->copy()->subMonthNoOverflow()->startOfMonth();
        $prevEnd = $today->copy()->subMonthNoOverflow()->endOfMonth();

        $pendingStatuses = ['issued', 'partial'];

        $scopeInvoices = fn ($query) => $ownDoctorId
            ? $query->where('attending_doctor_id', $ownDoctorId)
            : $query;
        $scopeViaInvoice = fn ($query) => $ownDoctorId
            ? $query->whereHas('invoice', fn ($q) => $q->where('attending_doctor_id', $ownDoctorId))
            : $query;

        $pendingCollections = (string) ($scopeInvoices(Invoice::query())
            ->whereIn('status', $pendingStatuses)
            ->selectRaw('COALESCE(SUM(total - amount_paid), 0) AS due')
            ->value('due') ?? '0');

        $monthRevenue = (string) ($scopeViaInvoice(Payment::query())
            ->whereBetween('payment_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('amount') ?? '0');

        $lastMonthRevenue = (string) ($scopeViaInvoice(Payment::query())
            ->whereBetween('payment_date', [$prevStart->toDateString(), $prevEnd->toDateString()])
            ->sum('amount') ?? '0');

        return [
            'month_revenue' => bcadd($monthRevenue, '0', 2),
            'last_month_revenue' => bcadd($lastMonthRevenue, '0', 2),
            'pending_collections' => bcadd($pendingCollections, '0', 2),
        ];
    }
}
