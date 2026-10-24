<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Elo\EloDictationRequest;
use App\Http\Requests\Elo\EloEventRequest;
use App\Http\Requests\Elo\EloExamsRequest;
use App\Http\Requests\Elo\EloNoteRequest;
use App\Http\Requests\Elo\EloPrescriptionRequest;
use App\Models\EloAgentSetting;
use App\Models\EloRule;
use App\Models\EloSuggestionEvent;
use App\Services\Elo\DictationSuggester;
use App\Services\Elo\EloAgents;
use App\Services\Elo\ExamSuggester;
use App\Services\Elo\GeminiUnavailable;
use App\Services\Elo\NoteSuggester;
use App\Services\Elo\PrescriptionSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

/**
 * Elo, the assistant inside the atención. It proposes; the doctor decides.
 * See decisions/ADR-017 in the knowledge base.
 */
class EloController extends Controller
{
    public function prescription(EloPrescriptionRequest $request, PrescriptionSuggester $suggester): JsonResponse
    {
        return $this->runAgent('prescription', fn (array $rules) => $suggester->suggest($request->validated(), $rules));
    }

    public function note(EloNoteRequest $request, NoteSuggester $suggester): JsonResponse
    {
        return $this->runAgent('note', fn (array $rules) => $suggester->suggest($request->validated(), $rules));
    }

    /** Elo listens: a dictated visit, sorted into fields. Its own agent, off until the doctor turns it on. */
    public function dictation(EloDictationRequest $request, DictationSuggester $suggester): JsonResponse
    {
        return $this->runAgent('dictation', fn (array $rules) => $suggester->suggest($request->validated(), $rules));
    }

    public function exams(EloExamsRequest $request, ExamSuggester $suggester): JsonResponse
    {
        return $this->runAgent('exams', fn (array $rules) => $suggester->suggest($request->validated(), $rules));
    }

    public function event(EloEventRequest $request): Response
    {
        EloSuggestionEvent::create([
            ...$request->validated(),
            'user_id' => Auth::id(),
        ]);

        return response()->noContent();
    }

    /**
     * What every agent shares: the doctor's switch, the doctor's own active
     * rules for that agent (part 2), and a quiet 503 when Gemini fails.
     *
     * @param  callable(list<string>): array<string, mixed>  $suggest
     */
    private function runAgent(string $agent, callable $suggest): JsonResponse
    {
        $userId = (int) Auth::id();

        if (! EloAgentSetting::isEnabled($userId, $agent)) {
            $name = EloAgents::ALL[$agent]['name'] ?? $agent;

            return response()->json(['message' => "Elo de {$this->lower($name)} está apagado."], 409);
        }

        $rules = EloRule::query()->mine($userId)
            ->where('agent', $agent)->where('enabled', true)
            ->oldest('id')->pluck('text')->all();

        try {
            return response()->json(['data' => $suggest($rules)]);
        } catch (GeminiUnavailable $e) {
            return self::unavailable($e);
        }
    }

    /** A quiet answer for the atención: over quota (429) is said plainly; anything else is a 503. */
    public static function unavailable(GeminiUnavailable $e): JsonResponse
    {
        return $e->overQuota()
            ? response()->json(['message' => 'Elo llegó a su límite de uso por ahora. Puedes seguir sin él.'], 429)
            : response()->json(['message' => 'Elo no está disponible ahora. Puedes seguir sin él.'], 503);
    }

    /** "Receta" → "receta", but "Nota y CIE-10" keeps its acronym. */
    private function lower(string $name): string
    {
        return mb_strtolower(mb_substr($name, 0, 1)).mb_substr($name, 1);
    }
}
