<?php

namespace App\Http\Resources;

use App\Models\Workspace;
use App\Support\PrivateImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

/**
 * Full workspace settings incl. fiscal / SRI configuration (Ajustes screen).
 *
 * The SRI certificate password is intentionally NEVER exposed — it is write-only
 * from the Filament panel. `sri_certificate_path` is reduced to a boolean flag.
 *
 * Letterhead image URLs are signed and expire — see PrivateImageUrl. The raw
 * `*_path` values stay in the payload: a path is useless without a signature.
 *
 * @mixin Workspace
 */
class WorkspaceSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = Auth::user();
        // isOwnedBy() covers non-doctor owners (owner_user_id) too — comparing
        // owner_doctor_id alone would mark e.g. a nurse-owner as non-owner.
        $isOwner = $user !== null && $this->resource->isOwnedBy($user);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_clinic' => $this->isClinic(),
            'is_owner' => $isOwner,
            'logo_path' => $this->logo_path,
            'logo_url' => PrivateImageUrl::for($this->logo_path),
            'header_image_path' => $this->header_image_path,
            'header_image_url' => PrivateImageUrl::for($this->header_image_path),
            'sheet_image_path' => $this->sheet_image_path,
            'sheet_image_url' => PrivateImageUrl::for($this->sheet_image_path),
            // Whether documents would print on something other than a blank
            // page. Derived here so the contextual warnings in the app and the
            // onboarding checklist cannot drift apart from the PDF pipeline.
            'has_letterhead' => $this->resource->hasLetterhead(),
            // Owner-only: whoever holds the code can invite members into the
            // clinic, so it must not reach employed members with settings.view
            // (same rule as ClinicController::inviteCode).
            'invite_code' => $this->when($this->isClinic() && $isOwner, $this->invite_code),

            // Fiscal / SRI configuration (Ecuador).
            'ruc' => $this->ruc,
            'razon_social' => $this->razon_social,
            'nombre_comercial' => $this->nombre_comercial,
            'dir_matriz' => $this->dir_matriz,
            'dir_establecimiento' => $this->dir_establecimiento,
            'cod_establecimiento' => $this->cod_establecimiento,
            'pto_emision' => $this->pto_emision,
            'contribuyente_especial' => $this->contribuyente_especial,
            'obligado_contabilidad' => (bool) $this->obligado_contabilidad,
            'acess_establishment_code' => $this->acess_establishment_code,
            'senescyt_note' => $this->senescyt_note,
            'sri_environment' => $this->sri_environment,
            'sri_enabled' => (bool) $this->sri_enabled,
            'sri_certificate_uploaded' => (bool) $this->sri_certificate_path,

            'prescription_settings' => $this->prescription_settings,
        ];
    }
}
