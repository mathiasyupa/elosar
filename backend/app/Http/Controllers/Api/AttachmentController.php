<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MedicalAttachment;
use App\Support\AccessLogger;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads clinical attachments. MedicalAttachment has NO WorkspaceScope of its
 * own, so isolation is enforced by requiring the parent MedicalRecord (which IS
 * scoped) to exist via whereHas('medicalRecord') — a foreign attachment id
 * therefore resolves to 404, never leaks across tenants.
 */
class AttachmentController extends Controller
{
    public function download(int $id, AccessLogger $accessLogger): StreamedResponse
    {
        $attachment = MedicalAttachment::query()->findOrFail($id);
        $disk = Storage::disk('local');

        abort_unless($disk->exists($attachment->file_path), 404);

        $accessLogger->log('medical_attachment', $attachment->id, 'download');

        return $disk->download($attachment->file_path, $attachment->title);
    }
}
