<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportAppointmentRequest;
use App\Services\AppointmentImportService;
use Illuminate\Http\JsonResponse;

class AppointmentImportController extends Controller
{
    public function store(ImportAppointmentRequest $request, AppointmentImportService $service): JsonResponse
    {
        $results = $service->import($request->validated('rows'));

        $created = count(array_filter($results, fn ($r) => $r['status'] === 'created'));
        $errors = count(array_filter($results, fn ($r) => $r['status'] === 'error'));

        return response()->json([
            'data' => [
                'created' => $created,
                'errors' => $errors,
                'rows' => $results,
            ],
        ]);
    }
}
