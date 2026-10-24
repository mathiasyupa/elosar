<?php

namespace App\Services\Reports;

use App\Models\MedicalRecord;
use App\Support\ActiveWorkspace;
use Illuminate\Support\Carbon;

/**
 * Builds the RDACAA dataset (Registro Diario Automatizado de Consultas y
 * Atenciones Ambulatorias) — one row per completed ambulatory attention in a
 * date range, in the terms the MSP catalogs expect.
 *
 * Everything reads through the WorkspaceScope-isolated MedicalRecord, so the
 * export can never cross tenants. The establishment "unicódigo" comes from the
 * workspace's ACESS code (acess_establishment_code).
 *
 * NOTE (pending B.0): the column set/order below is structurally faithful but
 * must be reconciled against the official current MSP RDACAA layout and its code
 * catalogs (etnia, nacionalidad, grupo prioritario, condición del diagnóstico)
 * before the file is submittable.
 */
class RdacaaExportService
{
    /**
     * CSV column headers — their order defines the file layout.
     *
     * @var list<string>
     */
    public const HEADERS = [
        'establecimiento',
        'fecha_atencion',
        'tipo_documento',
        'identificacion',
        'nombre_completo',
        'sexo',
        'fecha_nacimiento',
        'edad_anios',
        'nacionalidad',
        'autoidentificacion_etnica',
        'grupo_prioritario',
        'discapacidad_tipo',
        'discapacidad_pct',
        'tipo_atencion',
        'cie10_1',
        'condicion_1',
        'cie10_2',
        'condicion_2',
        'cie10_3',
        'condicion_3',
        'profesional',
        'registro_msp',
    ];

    public function __construct(private readonly ActiveWorkspace $activeWorkspace) {}

    /**
     * One associative row per completed attention in [$from, $to].
     *
     * @return list<array<string, scalar|null>>
     */
    public function rows(Carbon $from, Carbon $to, ?int $doctorId = null): array
    {
        $establishment = $this->activeWorkspace->current()?->acess_establishment_code;

        $records = MedicalRecord::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($doctorId, fn ($q, $d) => $q->where('created_by', $d))
            ->with(['patient', 'doctor', 'diagnoses'])
            ->orderBy('created_at')
            ->get();

        // Earliest completed attention per patient → primera vez vs subsecuente.
        $patientIds = $records->pluck('patient_id')->unique()->all();
        $firstAt = MedicalRecord::query()
            ->where('status', 'completed')
            ->whereIn('patient_id', $patientIds ?: [0])
            ->selectRaw('patient_id, MIN(created_at) AS first_at')
            ->groupBy('patient_id')
            ->pluck('first_at', 'patient_id');

        return $records->map(function (MedicalRecord $r) use ($establishment, $firstAt): array {
            $p = $r->patient;
            $dx = $r->diagnoses->values();

            $firstDate = isset($firstAt[$r->patient_id]) ? Carbon::parse($firstAt[$r->patient_id]) : null;
            $isFirstVisit = $firstDate !== null
                && $r->created_at !== null
                && $r->created_at->lessThanOrEqualTo($firstDate);

            return [
                'establecimiento' => $establishment,
                'fecha_atencion' => $r->created_at?->toDateString(),
                'tipo_documento' => $p?->document_type,
                'identificacion' => $p?->id_number,
                'nombre_completo' => $p?->name,
                'sexo' => $p?->gender,
                'fecha_nacimiento' => $p?->birth_date?->toDateString(),
                'edad_anios' => $p?->birth_date
                    ? (int) $p->birth_date->diffInYears($r->created_at ?? Carbon::now())
                    : null,
                'nacionalidad' => $p?->nationality,
                'autoidentificacion_etnica' => $p?->ethnicity,
                'grupo_prioritario' => $p?->priority_group,
                'discapacidad_tipo' => $p?->disability_type,
                'discapacidad_pct' => $p?->disability_pct,
                'tipo_atencion' => $isFirstVisit ? 'PRIMERA' : 'SUBSECUENTE',
                'cie10_1' => $dx[0]->cie10_code ?? null,
                'condicion_1' => isset($dx[0]) ? $this->condition($dx[0]->type) : null,
                'cie10_2' => $dx[1]->cie10_code ?? null,
                'condicion_2' => isset($dx[1]) ? $this->condition($dx[1]->type) : null,
                'cie10_3' => $dx[2]->cie10_code ?? null,
                'condicion_3' => isset($dx[2]) ? $this->condition($dx[2]->type) : null,
                'profesional' => $r->doctor?->name,
                'registro_msp' => $r->doctor?->medical_license,
            ];
        })->all();
    }

    /** Maps the stored diagnosis type to the RDACAA condition code. */
    private function condition(?string $type): string
    {
        return $type === 'presumptive' ? 'PRE' : 'DEF';
    }
}
