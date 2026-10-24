<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Reports\RdacaaExportService;
use App\Support\AccessLogger;
use App\Support\ActiveWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * RDACAA export (MSP ambulatory production report). Streams a CSV — one row per
 * completed attention — for the active workspace. Gated by reports.clinical so
 * the cashier (billing) can never pull identifiable clinical data.
 */
class RdacaaController extends Controller
{
    public function export(
        Request $request,
        RdacaaExportService $service,
        AccessLogger $accessLogger,
        ActiveWorkspace $activeWorkspace,
    ): StreamedResponse {
        // RDACAA is a daily/monthly production report — default to month-to-date.
        $to = ($request->date('to') ?? Carbon::today())->endOfDay();
        $from = ($request->date('from') ?? Carbon::today()->startOfMonth())->startOfDay();
        $doctorId = $request->integer('doctor_id') ?: null;

        $rows = $service->rows($from, $to, $doctorId);

        // LOPDP: a bulk export of identifiable clinical data (diagnoses per patient).
        $accessLogger->log('rdacaa_export', $activeWorkspace->id() ?? 0, 'export');

        $filename = 'rdacaa_'.$from->toDateString().'_'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel opens Spanish accents correctly.
            fwrite($out, "\xEF\xBB\xBF");
            // Ecuador locale spreadsheets expect ';' as the field separator.
            fputcsv($out, RdacaaExportService::HEADERS, ';');
            foreach ($rows as $row) {
                fputcsv($out, array_values($row), ';');
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
