<?php

namespace App\Services\Elo;

/**
 * The fixed catalog of Elo's agents (ADR-017). Copy is Spanish because it is
 * shown as-is in Ajustes → Agentes. `available` turns on as each part ships.
 */
final class EloAgents
{
    public const ALL = [
        'prescription' => [
            'name' => 'Receta',
            'description' => 'Propone una receta según el diagnóstico, respetando alergias y tus reglas.',
            'available' => true,
        ],
        'note' => [
            'name' => 'Nota y CIE-10',
            'description' => 'Ordena tu nota clínica y sugiere el código CIE-10.',
            'available' => true,
        ],
        'exams' => [
            'name' => 'Exámenes',
            'description' => 'Sugiere los exámenes de laboratorio e imagen para el caso.',
            'available' => true,
        ],
        // Off until each doctor turns it on: the microphone in the atención is
        // new, and should not surprise a clinic mid-shift.
        'dictation' => [
            'name' => 'Escuchar la consulta',
            'description' => 'Escucha la consulta por el micrófono y llena el motivo, la enfermedad actual, la evolución, el examen físico y el CIE-10.',
            'available' => true,
            'default_enabled' => false,
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    /** Whether an agent is on for a doctor who never touched its switch. */
    public static function enabledByDefault(string $agent): bool
    {
        return self::ALL[$agent]['default_enabled'] ?? true;
    }
}
