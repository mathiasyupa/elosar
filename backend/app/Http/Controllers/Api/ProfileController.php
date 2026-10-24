<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDoctorProfileRequest;
use App\Http\Resources\DoctorResource;
use App\Models\Doctor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * The authenticated doctor's professional profile (Perfil Profesional screen).
 * Runs under auth:sanctum only — no active workspace is required to read or edit
 * one's own profile.
 */
class ProfileController extends Controller
{
    public function show(): JsonResponse
    {
        $doctor = Auth::user()->doctor;

        if (! $doctor) {
            return response()->json(['message' => 'Esta cuenta no está asociada a un médico.'], 403);
        }

        return DoctorResource::make($doctor->load('specialties'))->response();
    }

    public function update(UpdateDoctorProfileRequest $request): JsonResponse
    {
        $doctor = Auth::user()->doctor;

        if (! $doctor) {
            return response()->json(['message' => 'Esta cuenta no está asociada a un médico.'], 403);
        }

        $validated = $request->validated();

        // Specialties live on the doctor_specialties pivot, not the doctors table.
        $specialtyIds = $validated['specialty_ids'] ?? null;
        unset($validated['specialty_ids']);

        // phone and city are NOT NULL columns, but a doctor may leave them
        // blank (an imported or clinic-registered account starts without
        // them): store a blank, never a null that fails the whole save.
        foreach (['phone', 'city'] as $required) {
            if (array_key_exists($required, $validated) && $validated[$required] === null) {
                $validated[$required] = '';
            }
        }

        $doctor->fill($validated);

        if ($specialtyIds !== null) {
            // First selected specialty is primary — it also fills the legacy
            // singular specialty_id column (printed on prescriptions, etc.),
            // consistent with doctor self-registration.
            $doctor->specialty_id = $specialtyIds[0];
        }

        $doctor->save();

        if ($specialtyIds !== null) {
            $doctor->specialties()->sync(
                collect($specialtyIds)->mapWithKeys(fn ($id, $i) => [$id => ['is_primary' => $i === 0]])
            );
        }

        return DoctorResource::make($doctor->fresh()->load('specialties'))->response();
    }

    /**
     * Upload (or replace) the doctor's handwritten signature image. This is the
     * image stamped on the prescription PDF / letterhead — NOT the SRI electronic
     * certificate (that lives in workspace settings).
     *
     * Stored on the private disk and only ever handed out through a signed,
     * expiring URL (App\Support\PrivateImageUrl). A readable signature image is
     * enough to forge a prescription, so it must never sit on the public disk.
     */
    public function uploadSignature(Request $request): JsonResponse
    {
        $doctor = Auth::user()->doctor;

        if (! $doctor) {
            return response()->json(['message' => 'Esta cuenta no está asociada a un médico.'], 403);
        }

        $request->validate([
            'signature' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);

        $this->deleteSignatureFile($doctor);

        $path = $request->file('signature')->store('signatures', 'local');
        $doctor->update(['signature_path' => $path]);

        return DoctorResource::make($doctor->fresh()->load('specialties'))->response();
    }

    public function deleteSignature(): JsonResponse
    {
        $doctor = Auth::user()->doctor;

        if (! $doctor) {
            return response()->json(['message' => 'Esta cuenta no está asociada a un médico.'], 403);
        }

        $this->deleteSignatureFile($doctor);
        $doctor->update(['signature_path' => null]);

        return DoctorResource::make($doctor->fresh()->load('specialties'))->response();
    }

    /**
     * Upload (or replace) the doctor's profile photo. Shown in the app chrome and
     * the prescription letterhead. Stored on the private disk like the signature.
     */
    public function uploadPhoto(Request $request): JsonResponse
    {
        $doctor = Auth::user()->doctor;

        if (! $doctor) {
            return response()->json(['message' => 'Esta cuenta no está asociada a un médico.'], 403);
        }

        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ]);

        $this->deletePhotoFile($doctor);

        $path = $request->file('photo')->store('doctor-photos', 'local');
        $doctor->update(['profile_photo_path' => $path]);

        return DoctorResource::make($doctor->fresh()->load('specialties'))->response();
    }

    public function deletePhoto(): JsonResponse
    {
        $doctor = Auth::user()->doctor;

        if (! $doctor) {
            return response()->json(['message' => 'Esta cuenta no está asociada a un médico.'], 403);
        }

        $this->deletePhotoFile($doctor);
        $doctor->update(['profile_photo_path' => null]);

        return DoctorResource::make($doctor->fresh()->load('specialties'))->response();
    }

    private function deleteSignatureFile(Doctor $doctor): void
    {
        if ($doctor->signature_path && Storage::disk('local')->exists($doctor->signature_path)) {
            Storage::disk('local')->delete($doctor->signature_path);
        }
    }

    private function deletePhotoFile(Doctor $doctor): void
    {
        if ($doctor->profile_photo_path && Storage::disk('local')->exists($doctor->profile_photo_path)) {
            Storage::disk('local')->delete($doctor->profile_photo_path);
        }
    }
}
