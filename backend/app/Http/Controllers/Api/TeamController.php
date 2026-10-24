<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ClinicalSeatLimitException;
use App\Http\Controllers\Controller;
use App\Mail\ClinicInviteMail;
use App\Models\ClinicInvite;
use App\Models\DoctorCommissionRule;
use App\Models\MedicalService;
use App\Models\WorkspaceMember;
use App\Support\ActiveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Clinic team administration (owner only — gated by permission:team.manage).
 * Issues email+role invitations and changes a member's role/status. Runs under
 * the `workspace` middleware so the active clinic is the bound workspace.
 */
class TeamController extends Controller
{
    /** Spanish labels for the clinic roles (email + UI parity). */
    private const ROLE_LABELS = [
        'doctor' => 'Médico',
        'nurse' => 'Enfermería',
        'receptionist' => 'Recepción',
        'billing' => 'Caja',
        'admin' => 'Administración',
    ];

    public function __construct(private readonly ActiveWorkspace $activeWorkspace) {}

    /**
     * Create an email + role invitation for the active clinic. v1 returns the
     * token + a redeem link (email delivery is Phase 5); the owner shares it.
     */
    public function invite(Request $request): JsonResponse
    {
        $workspace = $this->activeWorkspace->current();

        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in(ClinicInvite::ROLES)],
        ]);

        // Refuse a doctor invitation the clinic has no seat for, here and not
        // when the membership is finally written. Otherwise the invited doctor
        // receives the link, fills in cédula, specialties, phone and city, and
        // only then hits the wall — a terrible first contact with someone who
        // is not even our customer. A pending invitation still occupies no
        // seat; this is a check, not a reservation.
        $entitlement = $workspace->entitlement;

        if ($data['role'] === 'doctor'
            && $entitlement !== null
            && $workspace->isClinic()
            && $entitlement->isSeatLimitReached()) {
            throw new ClinicalSeatLimitException($entitlement->includedClinicalSeats());
        }

        $invite = ClinicInvite::create([
            'workspace_id' => $workspace->id,
            'email' => strtolower($data['email']),
            'role' => $data['role'],
            'token' => Str::random(40),
            'invited_by' => Auth::id(),
            'expires_at' => now()->addDays(14),
        ]);

        $redeemUrl = rtrim((string) (config('app.frontend_url') ?: config('app.url')), '/')
            .'/registro?token='.$invite->token;

        // Best-effort email — the owner always also gets the link in the response,
        // so a missing/failing mailer never blocks issuing the invitation.
        try {
            Mail::to($invite->email)->send(new ClinicInviteMail(
                $workspace->name,
                self::ROLE_LABELS[$invite->role] ?? $invite->role,
                $redeemUrl,
            ));
        } catch (\Throwable $e) {
            Log::warning('Clinic invite email failed: '.$e->getMessage());
        }

        return response()->json([
            'data' => [
                'token' => $invite->token,
                'email' => $invite->email,
                'role' => $invite->role,
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'redeem_path' => '/registro?token='.$invite->token,
            ],
        ], 201);
    }

    /**
     * Change a member's role or activate/deactivate them. The workspace owner is
     * protected: they cannot be deactivated or demoted out of ownership here.
     */
    public function updateMember(Request $request, int $memberId): JsonResponse
    {
        $workspace = $this->activeWorkspace->current();

        $member = $workspace->members()->whereKey($memberId)->firstOrFail();

        $data = $request->validate([
            // Multi-role: a member may hold several roles; permissions sum.
            'roles' => ['sometimes', 'array', 'min:1'],
            'roles.*' => [Rule::in(WorkspaceMember::ROLES)],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            // Doctor revenue-share (%). Nullable so the owner can clear it.
            'commission_pct' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $isOwner = $member->user_id !== null
            && (int) $workspace->owner_user_id === (int) $member->user_id;

        if ($isOwner && (($data['status'] ?? 'active') === 'inactive')) {
            return response()->json([
                'message' => 'No puedes desactivar al propietario de la clínica.',
            ], 422);
        }

        if (isset($data['roles'])) {
            $roles = array_values(array_unique($data['roles']));
            $data['roles'] = $roles;
            // Keep the legacy principal role in sync (doctor wins for badges).
            $data['role'] = in_array('doctor', $roles, true) ? 'doctor' : $roles[0];
        }

        $member->update($data);
        $member->load(['user', 'doctor', 'staffProfile']);

        return response()->json([
            'data' => [
                'id' => $member->id,
                'name' => $member->doctor?->name ?? $member->staffProfile?->name ?? $member->user?->name ?? '—',
                'email' => $member->user?->email,
                'role' => $member->role,
                'roles' => $member->roleList(),
                'status' => $member->status,
                'commission_pct' => $member->commission_pct,
                'account_type' => $member->user?->account_type,
                'phone' => $member->doctor?->phone ?? $member->staffProfile?->phone,
                'is_owner' => $isOwner,
            ],
        ]);
    }

    /**
     * Per-service commission overrides for a member doctor (owner only). Services
     * without a rule fall back to the doctor's global commission_pct.
     */
    public function commissionRules(int $memberId): JsonResponse
    {
        $workspace = $this->activeWorkspace->current();
        $member = $workspace->members()->whereKey($memberId)->firstOrFail();

        if (! $member->doctor_id) {
            return response()->json(['data' => []]);
        }

        $rules = DoctorCommissionRule::query()
            ->where('workspace_id', $workspace->id)
            ->where('doctor_id', $member->doctor_id)
            ->with('medicalService:id,name')
            ->get()
            ->map(fn (DoctorCommissionRule $r) => [
                'medical_service_id' => $r->medical_service_id,
                'service_name' => $r->medicalService?->name,
                'rule_type' => $r->rule_type,
                'value' => $r->value,
            ]);

        return response()->json(['data' => $rules]);
    }

    /**
     * Replace the whole per-service rule set for a member doctor (owner only).
     * Each rule is a percentage (0-100) or a fixed amount the doctor receives per
     * unit of the service. Services must belong to the active workspace.
     */
    public function setCommissionRules(Request $request, int $memberId): JsonResponse
    {
        $workspace = $this->activeWorkspace->current();
        $member = $workspace->members()->whereKey($memberId)->firstOrFail();

        if (! $member->doctor_id) {
            return response()->json(['message' => 'Solo los médicos tienen reparto por servicio.'], 422);
        }

        $data = $request->validate([
            'rules' => ['present', 'array'],
            'rules.*.medical_service_id' => ['required', 'integer'],
            'rules.*.rule_type' => ['required', Rule::in([DoctorCommissionRule::TYPE_PERCENTAGE, DoctorCommissionRule::TYPE_FIXED])],
            'rules.*.value' => ['required', 'numeric', 'min:0'],
        ]);

        // Services must belong to the active workspace (MedicalService is scoped).
        $validServiceIds = MedicalService::query()->pluck('id')->all();

        foreach ($data['rules'] as $rule) {
            if (! in_array((int) $rule['medical_service_id'], $validServiceIds, true)) {
                return response()->json(['message' => 'Servicio inválido para esta clínica.'], 422);
            }
            if ($rule['rule_type'] === DoctorCommissionRule::TYPE_PERCENTAGE && (float) $rule['value'] > 100) {
                return response()->json(['message' => 'El porcentaje no puede superar 100.'], 422);
            }
        }

        // Replace the doctor's whole rule set (dedupe by service — last wins).
        DoctorCommissionRule::query()
            ->where('workspace_id', $workspace->id)
            ->where('doctor_id', $member->doctor_id)
            ->delete();

        $seen = [];
        foreach ($data['rules'] as $rule) {
            $serviceId = (int) $rule['medical_service_id'];
            if (isset($seen[$serviceId])) {
                continue;
            }
            $seen[$serviceId] = true;

            DoctorCommissionRule::create([
                'workspace_id' => $workspace->id,
                'doctor_id' => $member->doctor_id,
                'medical_service_id' => $serviceId,
                'rule_type' => $rule['rule_type'],
                'value' => $rule['value'],
            ]);
        }

        return $this->commissionRules($memberId);
    }
}
