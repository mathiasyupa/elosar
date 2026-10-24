<?php

namespace App\Http\Controllers;

use App\Models\MedicalAttachment;
use App\Models\MedicalRecord;
use App\Models\Scopes\WorkspaceScope;
use App\Support\ActiveWorkspace;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Authenticated, workspace-scoped serving of medical attachments (PHI).
 *
 * Files live on the PRIVATE `local` disk and have no public URL. They are
 * streamed only to an authenticated doctor whose accessible workspaces include
 * the attachment's medical record.
 */
class MedicalAttachmentController extends Controller
{
    public function download(MedicalAttachment $attachment): StreamedResponse
    {
        // Resolve the owning record across ALL accessible workspaces (not only
        // the active one), bypassing the tenant scope deliberately for the check.
        $record = MedicalRecord::withoutGlobalScope(WorkspaceScope::class)
            ->find($attachment->medical_record_id);

        abort_if($record === null, 404);

        $accessible = app(ActiveWorkspace::class)->available()->pluck('id');

        abort_unless($accessible->contains($record->workspace_id), 403);

        $disk = Storage::disk('local');

        abort_unless($attachment->file_path && $disk->exists($attachment->file_path), 404);

        $downloadName = $attachment->title
            ? $attachment->title . '.' . pathinfo($attachment->file_path, PATHINFO_EXTENSION)
            : basename($attachment->file_path);

        return $disk->download($attachment->file_path, $downloadName);
    }
}
