<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AiChatRequest;
use App\Services\AiAssistantService;
use Illuminate\Http\JsonResponse;

/**
 * Chat with the clinical AI assistant. Thin controller — all tool-calling
 * orchestration and workspace-scoped data access lives in AiAssistantService.
 */
class AiAssistantController extends Controller
{
    public function chat(AiChatRequest $request, AiAssistantService $service): JsonResponse
    {
        $result = $service->chat(
            $request->validated('message'),
            $request->validated('history', [])
        );

        return response()->json(['data' => $result]);
    }
}
