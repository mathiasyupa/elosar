<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;

/**
 * Deletes payments (Cobros screen). Payment is workspace-scoped
 * (BelongsToWorkspace), so a foreign payment id resolves to 404; deletion
 * recalculates the parent invoice through PaymentService.
 */
class PaymentController extends Controller
{
    public function destroy(int $id, PaymentService $paymentService): JsonResponse
    {
        $payment = Payment::findOrFail($id);

        $paymentService->delete($payment);

        return response()->json(status: 204);
    }
}
