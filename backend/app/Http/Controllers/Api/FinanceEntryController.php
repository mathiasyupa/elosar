<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\FinanceEntry;
use App\Support\ActiveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The money the invoicing flow cannot know about: operating expenses, income
 * that never went through a patient invoice, and deductions agreed with a
 * doctor. Everything here is workspace-scoped (BelongsToWorkspace) and gated by
 * finance.view / finance.manage — the clinic's books belong to whoever owns or
 * administers it, not to the cash desk.
 */
class FinanceEntryController extends Controller
{
    /**
     * Movements of the active workspace. Filters: `?type=&from=&to=&category=`.
     */
    public function index(Request $request): JsonResponse
    {
        $from = $request->date('from')?->toDateString();
        $to = $request->date('to')?->toDateString();

        $entries = FinanceEntry::query()
            ->when($request->query('type'), fn ($q, $type) => $q->where('type', $type))
            ->when($request->query('category'), fn ($q, $category) => $q->where('category', $category))
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to))
            ->with(['registeredByUser:id,name', 'doctor:id,name'])
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->get();

        $totals = [];
        foreach (FinanceEntry::TYPES as $type) {
            $totals[$type] = $entries->where('type', $type)
                ->reduce(fn (string $carry, FinanceEntry $e) => bcadd($carry, (string) $e->amount, 2), '0.00');
        }

        return response()->json([
            'data' => $entries->map(fn (FinanceEntry $entry) => $this->present($entry))->all(),
            'meta' => [
                'count' => $entries->count(),
                'totals' => $totals,
            ],
        ]);
    }

    public function store(Request $request, ActiveWorkspace $activeWorkspace): JsonResponse
    {
        $data = $this->validated($request);
        $data['doctor_id'] = $this->resolveDoctorId($data, (int) $activeWorkspace->id());

        // workspace_id comes from the trait; the author is never client-supplied.
        $entry = FinanceEntry::create([...$data, 'registered_by' => Auth::id()]);
        $entry->load(['registeredByUser:id,name', 'doctor:id,name']);

        return response()->json([
            'message' => 'Movimiento registrado correctamente.',
            'data' => $this->present($entry),
        ], 201);
    }

    public function update(Request $request, ActiveWorkspace $activeWorkspace, int $id): JsonResponse
    {
        // findOrFail runs through WorkspaceScope: another clinic's row is a 404.
        $entry = FinanceEntry::findOrFail($id);

        $data = $this->validated($request);
        $data['doctor_id'] = $this->resolveDoctorId($data, (int) $activeWorkspace->id());

        $entry->update($data);
        $entry->load(['registeredByUser:id,name', 'doctor:id,name']);

        return response()->json([
            'message' => 'Movimiento actualizado correctamente.',
            'data' => $this->present($entry),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        FinanceEntry::findOrFail($id)->delete();

        return response()->json(['message' => 'Movimiento eliminado correctamente.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(FinanceEntry::TYPES)],
            'category' => ['required', 'string', 'max:50'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'entry_date' => ['required', 'date'],
            'doctor_id' => ['nullable', 'integer'],
            'receipt_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    /**
     * A deduction belongs to ONE doctor of THIS workspace; anything else would
     * silently discount money from a colleague of another clinic.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveDoctorId(array $data, int $workspaceId): ?int
    {
        if (($data['type'] ?? null) !== FinanceEntry::TYPE_DOCTOR_ADJUSTMENT) {
            return null;
        }

        $doctorId = $data['doctor_id'] ?? null;
        $belongs = $doctorId !== null && Doctor::query()
            ->whereKey($doctorId)
            ->where(fn ($q) => $q
                ->where('workspace_id', $workspaceId)
                ->orWhereHas('clinics', fn ($c) => $c->whereKey($workspaceId)))
            ->exists();

        if (! $belongs) {
            throw ValidationException::withMessages([
                'doctor_id' => 'Selecciona un médico de esta clínica para el descuento.',
            ]);
        }

        return (int) $doctorId;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FinanceEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'type' => $entry->type,
            'category' => $entry->category,
            'description' => $entry->description,
            'amount' => (string) $entry->amount,
            'entry_date' => $entry->entry_date instanceof Carbon
                ? $entry->entry_date->toDateString()
                : $entry->entry_date,
            'doctor_id' => $entry->doctor_id,
            'doctor_name' => $entry->doctor?->name,
            'receipt_number' => $entry->receipt_number,
            'notes' => $entry->notes,
            'registered_by' => $entry->registeredByUser?->name,
            'created_at' => $entry->created_at?->toIso8601String(),
        ];
    }
}
