<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportPatientRequest;
use App\Services\PatientImportService;
use Illuminate\Http\JsonResponse;

class PatientImportController extends Controller
{
    /**
     * Import patients in bulk from a parsed external file.
     *
     * Returns a per-row report so the UI can show created, duplicate and
     * failed rows without keeping the uploaded file in memory.
     */
    public function store(ImportPatientRequest $request, PatientImportService $service): JsonResponse
    {
        $dryRun = $request->boolean('dry_run');
        $import = $service->import($request->validated('rows'), $dryRun);
        $results = $import['results'];

        $created = count(array_filter($results, fn ($r) => $r['status'] === 'created'));
        $errors = count(array_filter($results, fn ($r) => $r['status'] === 'error'));

        return response()->json([
            'data' => [
                'committed' => $import['committed'],
                'ready' => $import['ready'],
                'created' => $created,
                'errors' => $errors,
                'rows' => $results,
            ],
        ], $dryRun || $import['committed'] ? 200 : 422);
    }
}
