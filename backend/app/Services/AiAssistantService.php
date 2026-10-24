<?php

namespace App\Services;

use App\Models\MedicalAttachment;
use App\Models\Patient;
use App\Services\Elo\GeminiClient;
use App\Services\Elo\GeminiUnavailable;
use App\Support\AccessLogger;
use RuntimeException;

/**
 * Clinical AI assistant: a tool-calling loop against the Gemini API.
 *
 * The model never touches the database. It can only read data through the
 * handlers below, which are plain Eloquent queries against workspace-scoped
 * models (Patient/MedicalRecord already carry BelongsToWorkspace) — so the
 * assistant automatically inherits the same tenant isolation as every other
 * controller, and can never see another workspace's patients. Reads of a
 * specific patient's data are recorded via AccessLogger (LODP), same as
 * MedicalRecordController/AttachmentController.
 */
class AiAssistantService
{
    /** Hard cap on tool-call round trips per question, to avoid infinite loops. */
    private const MAX_TOOL_ROUNDS = 5;

    /** Max patients returned by a single search, to keep prompts small. */
    private const SEARCH_LIMIT = 10;

    /**
     * Whitelisted in-app destinations the assistant can point the user to.
     * Keys are what the model passes; values drive the frontend's react-router
     * navigation. Kept in sync with the sidebar in AppLayout.tsx and the Ajustes
     * tabs in SettingsPage.tsx.
     *
     * @var array<string, array{route: string, label: string}>
     */
    private const PAGES = [
        'dashboard'        => ['route' => '/dashboard', 'label' => 'Dashboard'],
        'agenda'           => ['route' => '/agenda', 'label' => 'Agenda'],
        'pacientes'        => ['route' => '/pacientes', 'label' => 'Pacientes'],
        'documentos'       => ['route' => '/documentos', 'label' => 'Documentos'],
        'facturas'         => ['route' => '/facturas', 'label' => 'Facturas'],
        'seguros'          => ['route' => '/seguros', 'label' => 'Seguros'],
        'reportes'         => ['route' => '/reportes', 'label' => 'Reportes'],
        'servicios'        => ['route' => '/servicios', 'label' => 'Servicios'],
        'equipo'           => ['route' => '/equipo', 'label' => 'Equipo'],
        'clinicas'         => ['route' => '/clinicas', 'label' => 'Clínicas'],
        'perfil'           => ['route' => '/perfil', 'label' => 'Perfil'],
        'ajustes_general'  => ['route' => '/ajustes?pane=general', 'label' => 'Ajustes → General (Apariencia)'],
        'ajustes_membrete' => ['route' => '/ajustes?pane=letterhead', 'label' => 'Ajustes → Membrete e impresión'],
        'ajustes_sri'      => ['route' => '/ajustes?pane=fiscal', 'label' => 'Ajustes → Facturación SRI'],
    ];

    /** Navigation suggested by the most recent open_page tool call, if any. */
    private ?array $suggestedNavigation = null;

    public function __construct(
        private readonly AccessLogger $accessLogger,
        private readonly GeminiClient $gemini,
    )
    {
    }

    /**
     * @param  list<array{role: string, text: string}>  $history  Prior turns, oldest first. Stateless — the frontend replays it each request.
     * @return array{reply: string, navigate: ?array{route: string, label: string}}
     */
    public function chat(string $message, array $history = []): array
    {
        $this->suggestedNavigation = null;
        $contents = $this->buildContents($history, $message);

        // Gemini can narrate ("puedes cambiarlo en Ajustes...") in the SAME
        // turn it emits a tool call — collect text from every round, not just
        // the final one, or that narration is silently dropped.
        $replyChunks = [];

        for ($round = 0; $round < self::MAX_TOOL_ROUNDS; $round++) {
            $parts = $this->callGemini($contents);

            $text = $this->extractText($parts);
            if ($text !== '') {
                $replyChunks[] = $text;
            }

            $functionCalls = array_values(array_filter(
                $parts,
                static fn (array $part): bool => isset($part['functionCall'])
            ));

            if ($functionCalls === []) {
                return ['reply' => $this->joinReply($replyChunks), 'navigate' => $this->suggestedNavigation];
            }

            // Echo the model's turn (including its function-call requests) back
            // before sending the results, as the API requires.
            $contents[] = ['role' => 'model', 'parts' => $parts];

            $functionResponseParts = [];
            foreach ($functionCalls as $call) {
                $name = (string) ($call['functionCall']['name'] ?? '');
                $args = (array) ($call['functionCall']['args'] ?? []);

                $functionResponseParts[] = [
                    'functionResponse' => [
                        'name'     => $name,
                        'response' => $this->executeTool($name, $args),
                    ],
                ];
            }
            $contents[] = ['role' => 'user', 'parts' => $functionResponseParts];
        }

        $reply = $this->joinReply($replyChunks, 'No pude terminar de procesar tu pregunta. Intenta reformularla o ser más específico.');

        return ['reply' => $reply, 'navigate' => $this->suggestedNavigation];
    }

    /**
     * @param  list<string>  $chunks
     */
    private function joinReply(array $chunks, string $fallback = 'No encontré una respuesta para esa pregunta.'): string
    {
        $reply = implode("\n\n", $chunks);

        return $reply !== '' ? $reply : $fallback;
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return list<array<string, mixed>>
     */
    private function buildContents(array $history, string $message): array
    {
        $contents = [];

        foreach ($history as $turn) {
            $contents[] = [
                'role'  => ($turn['role'] ?? 'user') === 'assistant' ? 'model' : 'user',
                'parts' => [['text' => (string) ($turn['text'] ?? '')]],
            ];
        }

        $contents[] = ['role' => 'user', 'parts' => [['text' => $message]]];

        return $contents;
    }

    /**
     * Calls Gemini's generateContent endpoint and returns the response parts
     * of the first candidate.
     *
     * @param  list<array<string, mixed>>  $contents
     * @return list<array<string, mixed>>
     */
    private function callGemini(array $contents): array
    {
        abort_if(! $this->gemini->isConfigured(), 500, 'El asistente de IA no está configurado (falta GEMINI_API_KEY).');

        try {
            return $this->gemini->generate([
                'system_instruction' => ['parts' => [['text' => $this->systemPrompt()]]],
                'contents'           => $contents,
                'tools'              => [['function_declarations' => $this->toolDeclarations()]],
            ]);
        } catch (GeminiUnavailable) {
            throw new RuntimeException('El asistente de IA no está disponible en este momento.');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $parts
     */
    private function extractText(array $parts): string
    {
        return collect($parts)
            ->pluck('text')
            ->filter(static fn ($t) => is_string($t) && $t !== '')
            ->implode("\n");
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        Eres el asistente integrado en el panel de un consultorio médico en Ecuador (nombre
        del producto: Elosar). Respondes SIEMPRE en español, de forma breve y clara,
        dirigiéndote al médico o miembro del equipo que te consulta. Cumples dos roles:

        1) Datos clínicos del consultorio activo, usando herramientas de datos.
        2) Ayuda de uso de la interfaz (dónde está cada cosa, cómo hacer una acción),
           respondiendo con tu propio conocimiento de la app y ofreciendo un enlace con
           open_page cuando aplique.

        --- Datos clínicos (usa herramientas, nunca inventes) ---
        Solo puedes usar la información que te devuelven las herramientas disponibles —
        nunca inventes pacientes, diagnósticos ni datos clínicos. Si una herramienta no
        encuentra resultados, dilo explícitamente en vez de adivinar.

        Cuando el usuario pregunte por pacientes con un síntoma o diagnóstico, usa
        search_patients_by_condition. Cuando pregunte por el historial o los datos de un
        paciente concreto (ya identificado por nombre o por un resultado previo), usa
        get_patient_summary. Cuando pregunte dónde están los estudios, radiografías o
        adjuntos de un paciente, usa get_patient_attachments.

        IMPORTANTE — normaliza antes de buscar y reintenta si hace falta: la búsqueda es
        literal (no entiende sinónimos por sí sola), así que antes de llamar a
        search_patients_by_condition convierte el término coloquial o informal del usuario
        a su equivalente clínico estándar en español (ej. "panza"/"guata" → "abdomen",
        "gripa"/"resfriado" → "resfriado común" o "IRA", "presión alta" → "hipertensión").
        Si la primera búsqueda no encuentra nada y el término tenía una variante clínica
        razonable, vuelve a llamar la herramienta con ese término alternativo antes de
        decirle al usuario que no hay resultados. Solo informa "no encontré pacientes" después
        de haber probado la variante clínica más probable.

        Solo tienes acceso a los datos del consultorio/clínica activo del usuario —
        nunca asumas datos de otros consultorios.

        --- Ayuda de la interfaz ---
        Cuando el usuario pregunte dónde está algo o cómo llegar a una sección, explica
        brevemente en texto dónde encontrarlo Y llama a la herramienta open_page con la
        clave de esa página — la interfaz ya muestra un botón "Ir a..." automáticamente a
        partir de esa llamada, así que en tu texto NUNCA escribas la URL, la ruta ni un
        enlace en formato markdown (nada de corchetes ni paréntesis con "/ajustes..."):
        eso ya lo resuelve el botón, repetirlo se ve redundante. Es la única herramienta de
        esta sección — no consulta la base de datos, solo genera un enlace, así que puedes
        usarla libremente.

        Mapa de navegación de la app (barra lateral izquierda). La clave entre paréntesis
        es lo que debes pasar como "page" a open_page:
        - Dashboard (dashboard): resumen del día (citas, ingresos, cobros pendientes, pacientes recientes).
        - Agenda (agenda): citas en vista día/semana/mes, walk-in, check-in.
        - Pacientes (pacientes): listado y expediente (historia, vacunas, órdenes, recetas, adjuntos).
        - Documentos (documentos): biblioteca de todos los adjuntos clínicos del consultorio.
        - Facturas (facturas): facturación, pagos y cobros pendientes.
        - Seguros (seguros): pacientes con seguro médico registrado.
        - Reportes (reportes): ingresos y reportes financieros.
        - Servicios (servicios): catálogo de servicios/aranceles del consultorio.
        - Equipo (equipo): gestión de miembros del equipo (solo visible en workspaces de clínica).
        - Clínicas (clinicas): crear una clínica nueva o unirse con un código de invitación.
        - Perfil (perfil): datos profesionales del médico (firma, registro MSP, ACESS/SENESCYT).
        - Ajustes → General/Apariencia (ajustes_general): preferencias generales y tema visual.
        - Ajustes → Membrete e impresión (ajustes_membrete): logo y datos del membrete.
        - Ajustes → Facturación SRI (ajustes_sri): configuración de facturación electrónica.

        Barra superior: buscador de pacientes, botón "Nueva consulta", icono de sol/luna
        para alternar rápido el modo claro/oscuro, y el menú de usuario (cerrar sesión).

        Preguntas frecuentes:
        - "¿Cómo cambio el tema/estilo/apariencia?": el icono de sol/luna en la barra
          superior solo alterna claro/oscuro. Para elegir una paleta de estilo completa,
          ir a Ajustes → pestaña General → sección "Apariencia". Llama a
          open_page("ajustes_general").
        - "¿Cómo agrego una consulta/cita nueva?": botón "Nueva consulta" en la barra
          superior, o desde Agenda. Llama a open_page("agenda").
        - "¿Cómo invito a alguien de mi equipo?": Equipo → invitar (solo el dueño de la
          clínica puede hacerlo), o compartir el código de invitación desde Clínicas. Llama
          a open_page("equipo").
        - "¿Cómo configuro la facturación electrónica SRI?": Ajustes → pestaña
          "Facturación SRI". Llama a open_page("ajustes_sri").

        Si te preguntan algo de la interfaz que no está en este mapa, dilo con honestidad
        ("no tengo esa información") en vez de inventar una ubicación. No confundas ayuda
        de interfaz con datos clínicos: nunca uses este conocimiento general para responder
        preguntas sobre pacientes reales.
        PROMPT;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function toolDeclarations(): array
    {
        return [
            [
                'name'        => 'search_patients_by_condition',
                'description' => 'Busca pacientes del consultorio activo cuyo motivo de consulta, diagnóstico o descripción CIE-10 coincida con un síntoma o condición dada. Devuelve como máximo '.self::SEARCH_LIMIT.' coincidencias.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'term' => [
                            'type'        => 'string',
                            'description' => "Síntoma, diagnóstico o palabra clave a buscar, por ejemplo 'dolor de cabeza' o 'hipertensión'.",
                        ],
                    ],
                    'required' => ['term'],
                ],
            ],
            [
                'name'        => 'get_patient_summary',
                'description' => 'Obtiene el resumen clínico de un paciente específico del consultorio activo: datos básicos, alergias y motivo/diagnóstico de su última consulta.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'patient_id' => [
                            'type'        => 'integer',
                            'description' => 'Id del paciente, obtenido de una búsqueda previa.',
                        ],
                    ],
                    'required' => ['patient_id'],
                ],
            ],
            [
                'name'        => 'get_patient_attachments',
                'description' => 'Lista los adjuntos clínicos (radiografías, laboratorios, PDFs) registrados para un paciente específico del consultorio activo.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'patient_id' => [
                            'type'        => 'integer',
                            'description' => 'Id del paciente, obtenido de una búsqueda previa.',
                        ],
                    ],
                    'required' => ['patient_id'],
                ],
            ],
            [
                'name'        => 'open_page',
                'description' => 'Genera un enlace directo a una sección de la interfaz para que el usuario pueda ir ahí con un clic. No consulta datos clínicos ni de la base de datos.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'page' => [
                            'type'        => 'string',
                            'description' => 'Clave de la página a abrir.',
                            'enum'        => array_keys(self::PAGES),
                        ],
                    ],
                    'required' => ['page'],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function executeTool(string $name, array $args): array
    {
        return match ($name) {
            'search_patients_by_condition' => $this->searchPatientsByCondition((string) ($args['term'] ?? '')),
            'get_patient_summary'          => $this->getPatientSummary((int) ($args['patient_id'] ?? 0)),
            'get_patient_attachments'      => $this->getPatientAttachments((int) ($args['patient_id'] ?? 0)),
            'open_page'                    => $this->openPage((string) ($args['page'] ?? '')),
            default                        => ['error' => "Herramienta desconocida: {$name}"],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function openPage(string $key): array
    {
        $page = self::PAGES[$key] ?? null;

        if (! $page) {
            return ['error' => "Página no reconocida: {$key}"];
        }

        // Remembered so chat() can surface it to the frontend alongside the
        // model's text reply — this tool has no other side effect.
        $this->suggestedNavigation = $page;

        return ['ok' => true, ...$page];
    }

    /**
     * @return array<string, mixed>
     */
    private function searchPatientsByCondition(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return ['patients' => []];
        }

        // WorkspaceScope on Patient/MedicalRecord keeps this isolated to the
        // active workspace automatically — no manual workspace_id filter needed.
        $patients = Patient::query()
            ->whereHas('medicalRecords', function ($query) use ($term): void {
                $query->where(function ($inner) use ($term): void {
                    $inner->where('reason_for_consultation', 'ilike', "%{$term}%")
                        ->orWhere('current_illness', 'ilike', "%{$term}%")
                        ->orWhere('evolution', 'ilike', "%{$term}%")
                        ->orWhere('diagnosis', 'ilike', "%{$term}%")
                        ->orWhereHas('diagnoses', function ($d) use ($term): void {
                            $d->where('cie10_description', 'ilike', "%{$term}%");
                        });
                });
            })
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get(['id', 'name', 'id_number']);

        $result = [
            'patients' => $patients->map(fn (Patient $p): array => [
                'id'        => $p->id,
                'name'      => $p->name,
                'id_number' => $p->id_number,
            ])->all(),
        ];

        // The search is a literal ILIKE match — it can't find synonyms on its
        // own. Nudge the model to retry with a standard clinical term before
        // it reports "no results" for a colloquial phrase.
        if ($patients->isEmpty()) {
            $result['hint'] = "Sin resultados para \"{$term}\". Si es un término coloquial o informal, "
                .'intenta de nuevo con su equivalente clínico estándar en español antes de responder al usuario.';
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function getPatientSummary(int $patientId): array
    {
        $patient = Patient::with('allergies')->find($patientId);

        if (! $patient) {
            return ['error' => 'No se encontró ese paciente en el consultorio activo.'];
        }

        $this->accessLogger->log('patient', $patient->id, 'ai_assistant');

        $lastRecord = $patient->medicalRecords()->latest('created_at')->first();

        return [
            'id'                   => $patient->id,
            'name'                 => $patient->name,
            'id_number'            => $patient->id_number,
            'birth_date'           => $patient->birth_date?->toDateString(),
            'allergies'            => $patient->allergies->pluck('allergen_name')->all(),
            'last_visit_date'      => $lastRecord?->created_at?->toDateString(),
            'last_visit_reason'    => $lastRecord?->reason_for_consultation,
            'last_visit_diagnosis' => $lastRecord?->diagnosis,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPatientAttachments(int $patientId): array
    {
        $patient = Patient::find($patientId);

        if (! $patient) {
            return ['error' => 'No se encontró ese paciente en el consultorio activo.'];
        }

        $this->accessLogger->log('patient', $patient->id, 'ai_assistant');

        // MedicalAttachment has no WorkspaceScope of its own — isolation comes
        // from whereHas('medicalRecord'), same pattern as AttachmentController.
        $attachments = MedicalAttachment::query()
            ->whereHas('medicalRecord', fn ($q) => $q->where('patient_id', $patientId))
            ->latest('id')
            ->limit(20)
            ->get();

        return [
            'attachments' => $attachments->map(fn (MedicalAttachment $a): array => [
                'id'    => $a->id,
                'title' => $a->title,
                'notes' => $a->notes,
            ])->all(),
        ];
    }
}
