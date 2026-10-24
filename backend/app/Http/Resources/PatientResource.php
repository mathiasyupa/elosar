<?php

namespace App\Http\Resources;

use App\Models\Specialty;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Patient
 */
class PatientResource extends JsonResource
{
    /**
     * When true the resource emits the full clinical profile (single-patient
     * endpoints). Lists stay lightweight and never dump personal_background.
     */
    public bool $detailed = false;

    /**
     * Build a detailed instance for single-patient endpoints.
     */
    public static function detailed(mixed $resource): static
    {
        $instance = new static($resource);
        $instance->detailed = true;

        return $instance;
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id'               => $this->id,
            'name'             => $this->name,
            'id_number'        => $this->id_number,
            'document_type'    => $this->document_type,
            'document_country' => $this->document_country,
            'birth_date'       => $this->birth_date?->toDateString(),
            'age'        => $this->birth_date?->age,
            'gender'     => $this->gender,
            'phone'      => $this->phone,
            'email'      => $this->email,
            'city'       => $this->city,
            'address'    => $this->address,
            // Lightweight insurance flags so the Seguros list can render without
            // loading every patient's full profile.
            'insurance_provider'      => $this->insurance_provider,
            'insurance_policy_number' => $this->insurance_policy_number,
            // Most recent consultation date (present when loaded via withMax).
            'last_visit_at' => $this->whenHas(
                'medical_records_max_created_at',
                fn () => $this->medical_records_max_created_at
                    ? \Illuminate\Support\Carbon::parse($this->medical_records_max_created_at)->toDateString()
                    : null,
            ),
            // True when the patient has no prior consultation — drives the agenda
            // "Primera visita" badge. Derived from the same withMax aggregate so it
            // needs no extra query.
            'is_first_visit' => $this->whenHas(
                'medical_records_max_created_at',
                fn () => $this->medical_records_max_created_at === null,
            ),
        ];

        if (! $this->detailed) {
            return $data;
        }

        return array_merge($data, [
            'civil_status'            => $this->civil_status,
            'blood_type'              => $this->blood_type,
            'emergency_contact_name'  => $this->emergency_contact_name,
            'emergency_contact_phone' => $this->emergency_contact_phone,
            'insurance_provider'      => $this->insurance_provider,
            'insurance_policy_number' => $this->insurance_policy_number,
            'personal_background'     => $this->personal_background,
            // LOPDP consent status (detailed view only).
            'has_data_consent'        => $this->data_consent_at !== null,
            'data_consent_at'         => $this->data_consent_at?->toIso8601String(),
            'consent_marketing'       => (bool) $this->consent_marketing,
            'allergies' => $this->whenLoaded('allergies', fn () => $this->allergies->map(fn ($allergy) => [
                'id'            => $allergy->id,
                'allergen_type' => $allergy->allergen_type,
                'allergen_name' => $allergy->allergen_name,
                'severity'      => $allergy->severity,
                'notes'         => $allergy->notes,
            ])),
            'family_background' => $this->whenLoaded('familyBackground', fn () => $this->familyBackground->map(fn ($entry) => [
                'id'            => $entry->id,
                'relative_type' => $entry->relative_type,
                'disease'       => $entry->disease,
                'notes'         => $entry->notes,
            ])),
            // The pregnancy in progress travels with the patient, so gestational
            // age is on screen wherever she is, without a second request. Only
            // one can be active — the partial unique index guarantees it.
            // Only the unresolved ones: a closed alert is history, and the rail
            // pins these next to the patient's name.
            'alerts' => $this->whenLoaded(
                'alerts',
                fn () => PatientAlertResource::collection($this->alerts->whereNull('resolved_at')->values())
            ),
            'active_pregnancy' => $this->when(
                $this->maySeeActivePregnancy($request),
                fn () => $this->whenLoaded('pregnancies', function () {
                    $active = $this->pregnancies->firstWhere('status', 'active');

                    // Explicit null rather than an empty resource: most patients are
                    // not pregnant, and that is not a missing relation.
                    return $active === null ? null : PregnancyResource::make($active);
                }),
            ),
        ]);
    }

    /**
     * Pregnancy data is clinical data. This resource also serves write
     * endpoints, so it repeats the permission check that decides whether
     * PatientController::show() eager-loads the relation.
     */
    private function maySeeActivePregnancy(Request $request): bool
    {
        $user = $request->user();
        $workspace = app(ActiveWorkspace::class)->current();

        return $workspace !== null
            && $user?->doctor?->hasSpecialtyCapability(Specialty::CAPABILITY_GYNECO_OBSTETRICS)
            && app(WorkspacePermissionService::class)->can($user, $workspace, 'records.view');
    }
}
