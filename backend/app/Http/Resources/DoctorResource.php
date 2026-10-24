<?php

namespace App\Http\Resources;

use App\Models\Doctor;
use App\Support\PrivateImageUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Professional profile of the authenticated doctor (Perfil Profesional screen).
 *
 * The signature and photo URLs are signed and expire — see PrivateImageUrl. The
 * signature especially: a permanently readable signature image is a forgery kit
 * for prescriptions and medical certificates.
 *
 * @mixin Doctor
 */
class DoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Null until chosen: the app asks before the first document.
            'honorific' => $this->honorific,
            'uses_physical_stamp_signature' => (bool) $this->uses_physical_stamp_signature,
            'id_number' => $this->id_number,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'medical_license' => $this->medical_license,
            'acess_code' => $this->acess_code,
            'senescyt_registration' => $this->senescyt_registration,
            'specialty' => $this->primarySpecialty()?->name,
            'clinical_modules' => $this->clinicalModules(),
            'specialties' => $this->whenLoaded('specialties', fn () => $this->specialties->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'is_primary' => (bool) $s->pivot->is_primary,
            ])),
            'has_signature' => (bool) $this->signature_path,
            'signature_url' => PrivateImageUrl::for($this->signature_path),
            'has_photo' => (bool) $this->profile_photo_path,
            'photo_url' => PrivateImageUrl::for($this->profile_photo_path),
        ];
    }
}
