<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkspaceReadOnlyException extends HttpException
{
    public function __construct()
    {
        parent::__construct(Response::HTTP_LOCKED, 'Este workspace está en modo de solo lectura.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'workspace_read_only',
            'message' => $this->getMessage(),
        ], $this->getStatusCode());
    }
}
