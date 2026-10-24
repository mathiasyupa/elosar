<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\Appointment;
use App\Models\Invoice;
use App\Models\MedicalRecord;
use App\Models\MedicalService;
use App\Models\Patient;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * "Primeros pasos" checklist for a freshly created account.
 *
 * Every step is derived from real data (does a patient exist? was a
 * prescription PDF actually generated?) — never from a client-side flag — so
 * the list cannot claim something is done when it isn't, and it stays correct
 * for an account that was set up before this feature existed.
 *
 * The step list is role-aware: it is assembled from the caller's effective
 * permissions, using WorkspacePermissionService as the single source of truth
 * rather than re-deriving role rules. A cashier therefore gets a money-shaped
 * checklist and never sees clinical steps.
 */
class OnboardingController extends Controller
{
    public function __construct(
        private readonly ActiveWorkspace $activeWorkspace,
        private readonly WorkspacePermissionService $permissions,
    ) {}

    public function show(): JsonResponse
    {
        $user = Auth::user();
        $workspace = $this->activeWorkspace->current();

        if (! $workspace) {
            return response()->json(['dismissed' => true, 'steps' => []]);
        }

        $can = fn (string $permission): bool => $this->permissions->can($user, $workspace, $permission);

        // A user who can keep clinical records gets the clinical checklist; the
        // money steps are reserved for people whose job IS the money (cashier,
        // front desk), so a doctor's list stays short enough to actually finish.
        $isClinical = $can('records.manage');

        $steps = [];

        if ($can('settings.manage')) {
            $steps[] = $this->step(
                'letterhead',
                'Configura tu membrete',
                'Aparecerá en las recetas, órdenes y certificados que entregues.',
                '/ajustes',
                $this->hasLetterhead(),
            );
        }

        if ($can('patients.manage')) {
            $steps[] = $this->step(
                'first_patient',
                'Registra tu primer paciente',
                'Es el punto de partida de la historia clínica.',
                '/pacientes',
                Patient::query()->exists(),
            );
        }

        if ($can('appointments.manage')) {
            $steps[] = $this->step(
                'first_appointment',
                'Agenda tu primera cita',
                'Organiza el día y activa los recordatorios al paciente.',
                '/agenda',
                Appointment::query()->exists(),
            );
        }

        if ($isClinical) {
            $steps[] = $this->step(
                'first_consultation',
                'Cierra tu primera consulta',
                'Registra motivo, diagnóstico CIE-10 y tratamiento.',
                '/pacientes',
                MedicalRecord::query()->where('status', 'completed')->exists(),
            );

            // A prescription printed on a blank page is not "listo para
            // entregar", so generating the PDF is only half the step: the
            // letterhead has to actually print something. Someone who cannot
            // reach Ajustes is not held to a requirement they cannot meet —
            // for them the generated PDF is the whole of the step.
            $prescriptionDone = $this->hasGeneratedPrescription($workspace->id)
                && (! $can('settings.manage') || $this->hasLetterhead());

            $steps[] = $this->step(
                'first_prescription',
                'Descarga tu primera receta',
                'Genera el PDF en formato MSP, listo para entregar o enviar.',
                '/pacientes',
                $prescriptionDone,
            );
        }

        if (! $isClinical && $can('billing.manage')) {
            $steps[] = $this->step(
                'first_service',
                'Carga tu tarifario',
                'Los servicios y precios que se cobran en el consultorio.',
                '/servicios',
                MedicalService::query()->exists(),
            );

            $steps[] = $this->step(
                'first_invoice',
                'Emite tu primera factura',
                'Registra el cobro y entrega el comprobante al paciente.',
                '/facturas',
                Invoice::query()->exists(),
            );
        }

        $completed = count(array_filter($steps, fn (array $s): bool => $s['done']));

        return response()->json([
            'dismissed' => $user->onboarding_dismissed_at !== null,
            'completed' => $completed,
            'total' => count($steps),
            'steps' => $steps,
        ]);
    }

    /** Hides the checklist for good, across every device this account uses. */
    public function dismiss(): JsonResponse
    {
        $user = Auth::user();
        $user->forceFill(['onboarding_dismissed_at' => now()])->save();

        return response()->json(['dismissed' => true]);
    }

    /**
     * @return array{key: string, title: string, description: string, route: string, done: bool}
     */
    private function step(string $key, string $title, string $description, string $route, bool $done): array
    {
        return compact('key', 'title', 'description', 'route', 'done');
    }

    /** @see Workspace::hasLetterhead() — the rule lives on the model. */
    private function hasLetterhead(): bool
    {
        return $this->activeWorkspace->current()->hasLetterhead();
    }

    /**
     * Reads the LOPDP access log, the only place that records a prescription
     * PDF actually being produced (the PDF itself is not persisted).
     * AccessLog has no workspace global scope, so it is filtered explicitly.
     */
    private function hasGeneratedPrescription(int $workspaceId): bool
    {
        return AccessLog::query()
            ->where('workspace_id', $workspaceId)
            ->where('action', 'prescription_pdf')
            ->exists();
    }
}
