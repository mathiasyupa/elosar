<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Raised when a clinic tries to put one more attending doctor on the floor
 * than its plan includes. Reception, nursing, billing and administration never
 * reach this: only clinical seats are counted, because only they are billed.
 */
class ClinicalSeatLimitException extends HttpException
{
    public function __construct(private readonly int $includedSeats)
    {
        parent::__construct(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            "El plan de esta clínica incluye {$includedSeats} médicos activos. Para sumar otro, escríbenos y ampliamos el cupo.",
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => 'clinical_seat_limit_reached',
            'message' => $this->getMessage(),
            'included_seats' => $this->includedSeats,
        ], $this->getStatusCode());
    }
}
