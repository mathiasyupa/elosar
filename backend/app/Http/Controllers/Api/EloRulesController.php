<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Elo\EloRuleRequest;
use App\Http\Requests\Elo\EloTeachRequest;
use App\Models\EloAgentSetting;
use App\Models\EloRule;
use App\Models\EloSuggestionEvent;
use App\Services\Elo\EloAgents;
use App\Services\Elo\GeminiUnavailable;
use App\Services\Elo\RuleTeacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

/**
 * Ajustes → Agentes (ADR-017, part 2): each doctor's switches and rules for
 * Elo, and the chat that proposes rules. Everything is scoped to the active
 * workspace (trait) AND to the signed-in doctor (`mine()`).
 */
class EloRulesController extends Controller
{
    public function agents(): JsonResponse
    {
        $userId = (int) Auth::id();

        $rules = EloRule::query()->mine($userId)->selectRaw('agent, count(*) as n')->groupBy('agent')->pluck('n', 'agent');
        $switches = EloAgentSetting::query()->where('user_id', $userId)->pluck('enabled', 'agent');
        $decisions = EloSuggestionEvent::query()
            ->where('user_id', $userId)
            ->where('created_at', '>=', now()->subDays(30))
            ->whereIn('outcome', ['accepted', 'edited', 'rejected'])
            ->selectRaw("agent, count(*) as total, sum(case when outcome = 'rejected' then 0 else 1 end) as taken")
            ->groupBy('agent')
            ->get()
            ->keyBy('agent');

        $data = collect(EloAgents::ALL)->map(function (array $agent, string $key) use ($rules, $switches, $decisions) {
            $d = $decisions->get($key);

            return [
                'key' => $key,
                ...Arr::except($agent, 'default_enabled'),
                'enabled' => (bool) ($switches[$key] ?? EloAgents::enabledByDefault($key)),
                'rules_count' => (int) ($rules[$key] ?? 0),
                'accepted_rate' => $d && $d->total > 0 ? (int) round($d->taken * 100 / $d->total) : null,
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function toggle(Request $request, string $agent): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        EloAgentSetting::query()->updateOrCreate(
            ['user_id' => Auth::id(), 'agent' => $agent],
            ['enabled' => $data['enabled']],
        );

        return response()->json(['data' => ['key' => $agent, 'enabled' => (bool) $data['enabled']]]);
    }

    public function rules(string $agent): JsonResponse
    {
        return response()->json(['data' => $this->ruleList($agent)]);
    }

    public function store(EloRuleRequest $request, string $agent): JsonResponse
    {
        $texts = $request->texts();
        $count = EloRule::query()->mine((int) Auth::id())->where('agent', $agent)->count();

        if ($count + count($texts) > EloRule::MAX_PER_AGENT) {
            return response()->json([
                'message' => 'Elo guarda hasta '.EloRule::MAX_PER_AGENT.' reglas por agente. Borra alguna para agregar más.',
            ], 422);
        }

        foreach ($texts as $text) {
            EloRule::create(['user_id' => Auth::id(), 'agent' => $agent, 'text' => $text]);
        }

        return response()->json(['data' => $this->ruleList($agent)], 201);
    }

    public function update(EloRuleRequest $request, int $rule): JsonResponse
    {
        $model = EloRule::query()->mine((int) Auth::id())->findOrFail($rule);
        $model->update($request->safe()->only(['text', 'enabled']));

        return response()->json(['data' => $this->present($model)]);
    }

    public function destroy(int $rule): Response
    {
        EloRule::query()->mine((int) Auth::id())->findOrFail($rule)->delete();

        return response()->noContent();
    }

    public function teach(EloTeachRequest $request, string $agent, RuleTeacher $teacher): JsonResponse
    {
        $current = EloRule::query()->mine((int) Auth::id())->where('agent', $agent)->pluck('text')->all();

        try {
            return response()->json(['data' => $teacher->teach(
                $agent,
                $request->validated('message'),
                $request->validated('history') ?? [],
                $current,
            )]);
        } catch (GeminiUnavailable $e) {
            return EloController::unavailable($e);
        }
    }

    /** @return list<array<string, mixed>> */
    private function ruleList(string $agent): array
    {
        return EloRule::query()->mine((int) Auth::id())->where('agent', $agent)->oldest('id')->get()
            ->map(fn (EloRule $r) => $this->present($r))->all();
    }

    /** @return array<string, mixed> */
    private function present(EloRule $rule): array
    {
        return ['id' => $rule->id, 'agent' => $rule->agent, 'text' => $rule->text, 'enabled' => $rule->enabled];
    }
}
