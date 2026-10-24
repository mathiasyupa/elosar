<?php

namespace App\Services\Elo;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The one place that talks to Gemini. The chat assistant sends free
 * conversation with tools; Elo's agents ask for JSON that matches a schema, so
 * their answers are data the app can check — never prose to parse.
 */
class GeminiClient
{
    public function isConfigured(): bool
    {
        return (bool) config('services.gemini.key');
    }

    /**
     * Raw generateContent call. Returns the first candidate's parts.
     *
     * @param  array<string, mixed>  $payload  system_instruction, contents, tools, generationConfig…
     * @return list<array<string, mixed>>
     */
    public function generate(array $payload, int $timeoutSeconds = 30): array
    {
        $apiKey = config('services.gemini.key');
        $model = config('services.gemini.model');

        if (! $apiKey) {
            throw new GeminiUnavailable('Falta GEMINI_API_KEY.');
        }

        // Gemini answers 503 "overloaded" now and then; one quick retry hides it
        // from the doctor. Anything else (bad key, 4xx) fails at once.
        $response = Http::withHeaders(['x-goog-api-key' => $apiKey])
            ->timeout($timeoutSeconds)
            ->retry(2, 400, fn ($e) => $e instanceof RequestException && in_array($e->response->status(), [500, 502, 503], true), throw: false)
            ->post("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent", $payload);

        if ($response->failed()) {
            Log::warning('Gemini API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new GeminiUnavailable('Gemini respondió '.$response->status().'.', $response->status());
        }

        return $response->json('candidates.0.content.parts', []);
    }

    /**
     * A structured answer: Gemini is constrained to $schema and the JSON is
     * decoded. Anything that is not valid JSON is treated as "no answer".
     *
     * @param  array<string, mixed>  $schema  OpenAPI-style schema (Gemini responseSchema)
     * @return array<string, mixed>
     */
    public function generateJson(string $system, string $prompt, array $schema, int $timeoutSeconds = 25): array
    {
        $parts = $this->generate([
            'system_instruction' => ['parts' => [['text' => $system]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
                'temperature' => 0.2,
                // 2.5 Flash "thinks" before answering by default, which is most of
                // the wait. Elo's tasks are short and structured, and the fixed
                // rules (allergies) run afterwards anyway: answer straight away.
                'thinkingConfig' => ['thinkingBudget' => 0],
            ],
        ], $timeoutSeconds);

        $text = collect($parts)->pluck('text')->filter(fn ($t) => is_string($t))->implode('');
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new GeminiUnavailable('Gemini no devolvió JSON válido.');
        }

        return $decoded;
    }
}
