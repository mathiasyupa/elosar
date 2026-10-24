<?php

namespace App\Services\Reports;

use App\Models\DoctorCommissionRule;
use App\Models\FinanceEntry;
use App\Models\Invoice;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes the doctor settlement ("liquidación por médico") for the active
 * workspace: how much of what was collected belongs to each attending doctor and
 * how much stays with the clinic.
 *
 * Rules (per invoice, in precedence order):
 *  - Per-invoice override (invoices.attending_doctor_commission_pct): the % the
 *    cashier agreed for THIS attention — wins over everything below.
 *  - Per-service override (DoctorCommissionRule): a percentage of the line, or a
 *    fixed amount the doctor receives per unit of that service.
 *  - Otherwise the doctor's global commission_pct (workspace_members) applies.
 *  - A doctor with none of the above has no split (amounts are null, not zero).
 *
 * Basis = collected money, optionally restricted to a period: only payments
 * inside [$from, $to] count, and each invoice's collected/total ratio prorates
 * the share, so a partially-paid invoice pays the doctor proportionally. A
 * uniform global percentage reduces exactly to collected * pct/100. All math is
 * bcmath. Passing no period keeps the historic (all-time) behaviour.
 *
 * Deductions agreed with a doctor (supplies they consumed, advances already
 * handed over) are FinanceEntry rows of type doctor_adjustment; they lower that
 * doctor's payout and raise what the clinic keeps.
 */
class DoctorSettlementService
{
    /**
     * Settlement aggregated per attending doctor.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forWorkspace(int $workspaceId, ?Carbon $from = null, ?Carbon $to = null): Collection
    {
        $globalPct = $this->globalPercentages($workspaceId);
        $rules = $this->allRules($workspaceId);
        $adjustments = $this->adjustmentsByDoctor($workspaceId, $from, $to);

        /** @var array<int, array<string, mixed>> $acc keyed by doctor_id (0 = unassigned) */
        $acc = [];
        foreach ($this->invoiceQuery($from, $to)->get() as $invoice) {
            $doctorId = $invoice->attending_doctor_id ? (int) $invoice->attending_doctor_id : null;
            $key = $doctorId ?? 0;
            $collected = bcadd((string) ($invoice->collected ?? '0'), '0', 6);

            $doctorRules = $doctorId !== null ? ($rules[$doctorId] ?? []) : [];
            $pct = $doctorId !== null && isset($globalPct[$doctorId]) ? (string) $globalPct[$doctorId] : null;

            [$share, $invoicePct, $hasConfig] = $this->scoreInvoice($invoice, $collected, $doctorRules, $pct);

            if (! isset($acc[$key])) {
                $acc[$key] = [
                    'doctor_id' => $doctorId,
                    'name' => $invoice->attendingDoctor?->name ?? 'Sin asignar',
                    'count' => 0,
                    'collected' => '0',
                    'share' => '0',
                    'pct' => $pct,
                    'hasConfig' => false,
                ];
            }
            // Prefer showing the doctor's global % in the header; fall back to a
            // per-invoice % so a doctor billed only with ad-hoc %s still shows one.
            $acc[$key]['pct'] ??= $invoicePct;
            $acc[$key]['hasConfig'] = $acc[$key]['hasConfig'] || $hasConfig;
            $acc[$key]['count']++;
            $acc[$key]['collected'] = bcadd($acc[$key]['collected'], $collected, 6);
            $acc[$key]['share'] = bcadd($acc[$key]['share'], $share, 6);
        }

        return collect($acc)
            ->map(function (array $row) use ($adjustments) {
                $total = bcadd($row['collected'], '0', 2);
                $deducted = $row['doctor_id'] !== null
                    ? ($adjustments[$row['doctor_id']] ?? '0.00')
                    : '0.00';

                if (! $row['hasConfig']) {
                    return [
                        'doctor_id' => $row['doctor_id'],
                        'name' => $row['name'],
                        'count' => $row['count'],
                        'total' => $total,
                        'commission_pct' => null,
                        'doctor_amount' => null,
                        'adjustments' => $deducted,
                        'doctor_net' => null,
                        'clinic_amount' => null,
                    ];
                }

                // Cap the doctor's amount at what was collected; the clinic keeps the rest.
                $doctorAmount = bcadd($row['share'], '0', 2);
                if (bccomp($doctorAmount, $total, 2) === 1) {
                    $doctorAmount = $total;
                }

                // Agreed deductions come off the payout, never below zero.
                $doctorNet = bcsub($doctorAmount, $deducted, 2);
                if (bccomp($doctorNet, '0', 2) === -1) {
                    $doctorNet = '0.00';
                }

                return [
                    'doctor_id' => $row['doctor_id'],
                    'name' => $row['name'],
                    'count' => $row['count'],
                    'total' => $total,
                    'commission_pct' => $row['pct'] !== null ? bcadd($row['pct'], '0', 2) : null,
                    'doctor_amount' => $doctorAmount,
                    'adjustments' => $deducted,
                    'doctor_net' => $doctorNet,
                    'clinic_amount' => bcsub($total, $doctorNet, 2),
                ];
            })
            ->sortByDesc(fn ($row) => (float) $row['total'])
            ->values();
    }

    /**
     * The same settlement opened up attention by attention — one row per invoice
     * line, which is how a clinic audits the month ("this echo, this patient,
     * this date, this much to the doctor"). Rows add up EXACTLY to the totals of
     * forWorkspace(): the split of each invoice is distributed across its lines
     * with a largest-remainder allocation, never re-derived.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function detailForWorkspace(
        int $workspaceId,
        ?Carbon $from = null,
        ?Carbon $to = null,
        ?int $doctorId = null,
    ): Collection {
        $globalPct = $this->globalPercentages($workspaceId);
        $rules = $this->allRules($workspaceId);

        $invoices = $this->invoiceQuery($from, $to)
            ->when($doctorId, fn (Builder $q) => $q->where('attending_doctor_id', $doctorId))
            ->with('patient:id,name,id_number')
            ->get();

        $rows = [];
        foreach ($invoices as $invoice) {
            $attendingId = $invoice->attending_doctor_id ? (int) $invoice->attending_doctor_id : null;
            $collected = bcadd((string) ($invoice->collected ?? '0'), '0', 6);

            $doctorRules = $attendingId !== null ? ($rules[$attendingId] ?? []) : [];
            $pct = $attendingId !== null && isset($globalPct[$attendingId]) ? (string) $globalPct[$attendingId] : null;

            [$share, $invoicePct, $hasConfig, $invoiceAmount] = $this->scoreInvoice($invoice, $collected, $doctorRules, $pct);
            $shareRounded = bcadd($share, '0', 2);
            $collectedRounded = bcadd($collected, '0', 2);
            if (bccomp($shareRounded, $collectedRounded, 2) === 1) {
                $shareRounded = $collectedRounded;
            }

            $items = $invoice->items;
            $weights = $items->map(fn ($item) => bcadd((string) $item->line_total, '0', 6))->all();

            // What the invoice actually brought in, split across its lines, and
            // the doctor's cut of each of them.
            $collectedByLine = $items->isEmpty() ? [] : $this->allocate($weights, $collectedRounded);
            $shareByLine = $items->isEmpty()
                ? []
                : $this->allocate(
                    $this->lineShares($invoice, $collected, $doctorRules, $pct, $invoicePct, $invoiceAmount),
                    $shareRounded,
                );

            $common = [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'date' => $invoice->issued_at?->toDateString(),
                'doctor_id' => $attendingId,
                'doctor_name' => $invoice->attendingDoctor?->name ?? 'Sin asignar',
                'patient_name' => $invoice->buyer_name ?: ($invoice->patient?->name ?? 'Sin paciente'),
                'patient_id_number' => $invoice->buyer_id ?: $invoice->patient?->id_number,
                'status' => $invoice->status,
                // A line whose invoice is only half paid shows half of it here.
                'is_partial' => bccomp($collectedRounded, bcadd((string) $invoice->total, '0', 2), 2) === -1,
            ];

            if ($items->isEmpty()) {
                $rows[] = [
                    ...$common,
                    'service' => 'Factura sin detalle',
                    'quantity' => 1,
                    'price' => bcadd((string) $invoice->total, '0', 2),
                    'collected' => $collectedRounded,
                    'doctor_amount' => $hasConfig ? $shareRounded : null,
                    'clinic_amount' => $hasConfig ? bcsub($collectedRounded, $shareRounded, 2) : null,
                ];

                continue;
            }

            foreach ($items->values() as $i => $item) {
                $lineCollected = $collectedByLine[$i] ?? '0.00';
                $lineShare = $shareByLine[$i] ?? '0.00';

                $rows[] = [
                    ...$common,
                    'service' => $item->description,
                    'quantity' => (int) $item->quantity,
                    'price' => bcadd((string) $item->line_total, '0', 2),
                    'collected' => $lineCollected,
                    'doctor_amount' => $hasConfig ? $lineShare : null,
                    'clinic_amount' => $hasConfig ? bcsub($lineCollected, $lineShare, 2) : null,
                ];
            }
        }

        // Newest attention first, then by patient so a same-day family reads together.
        return collect($rows)->sortBy([
            fn (array $a, array $b) => ($b['date'] ?? '') <=> ($a['date'] ?? ''),
            fn (array $a, array $b) => $a['patient_name'] <=> $b['patient_name'],
        ])->values();
    }

    /**
     * Per-service commission rules for ONE doctor, keyed by medical_service_id.
     * Load once and pass to shareForInvoice() when scoring several invoices.
     *
     * @return array<int, array{type: string, value: string}>
     */
    public function rulesForDoctor(int $workspaceId, int $doctorId): array
    {
        $rules = [];
        foreach (DoctorCommissionRule::query()->where('workspace_id', $workspaceId)->where('doctor_id', $doctorId)->get() as $rule) {
            $rules[$rule->medical_service_id] = [
                'type' => $rule->rule_type,
                'value' => (string) $rule->value,
            ];
        }

        return $rules;
    }

    /** The doctor's global revenue-share % in this workspace, or null. */
    public function globalPctForDoctor(int $workspaceId, int $doctorId): ?string
    {
        $pct = WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->where('doctor_id', $doctorId)
            ->value('commission_pct');

        return $pct !== null ? (string) $pct : null;
    }

    /**
     * The attending doctor's share of ONE invoice, using the same precedence and
     * bcmath as the settlement report — so the invoice list and "Liquidación por
     * médico" can never disagree. Requires the invoice to carry `collected`
     * (withSum('payments as collected', 'amount')) and its items.
     *
     * @param  array<int, array{type: string, value: string}>  $doctorRules
     */
    public function shareForInvoice(Invoice $invoice, array $doctorRules = [], ?string $globalPct = null): string
    {
        $collected = bcadd((string) ($invoice->collected ?? '0'), '0', 6);

        [$share] = $this->scoreInvoice($invoice, $collected, $doctorRules, $globalPct);

        // Never more than what was actually collected.
        if (bccomp($share, $collected, 6) === 1) {
            $share = $collected;
        }

        return bcadd($share, '0', 2);
    }

    // ── Building blocks ──────────────────────────────────────────────────────

    /**
     * Invoices with money collected in the window (all invoices with payments
     * when no window is given). `collected` carries ONLY the payments inside the
     * period, which is what makes the settlement a period settlement.
     * WorkspaceScope keeps this tenant-isolated.
     *
     * @return Builder<Invoice>
     */
    private function invoiceQuery(?Carbon $from, ?Carbon $to): Builder
    {
        $window = fn ($query) => $query
            ->when($from, fn ($q) => $q->where('payment_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('payment_date', '<=', $to->toDateString()));

        return Invoice::query()
            // The select goes FIRST: withSum appends its subquery to the column
            // list, and a later select() would wipe `collected` off the query.
            ->select([
                'id', 'workspace_id', 'patient_id', 'invoice_number', 'issued_at', 'status',
                'attending_doctor_id', 'attending_doctor_commission_pct',
                'attending_doctor_commission_amount', 'total', 'buyer_name', 'buyer_id',
            ])
            ->whereHas('payments', $window)
            ->withSum(['payments as collected' => $window], 'amount')
            ->with([
                'items:id,invoice_id,medical_service_id,description,line_total,quantity',
                'attendingDoctor:id,name',
            ]);
    }

    /**
     * Score one invoice: the doctor's raw share, the per-invoice % if any, and
     * whether the doctor has ANY commission configuration at all (no config =
     * no split, which is not the same as a 0% split).
     *
     * @param  array<int, array{type: string, value: string}>  $doctorRules
     * @return array{0: string, 1: ?string, 2: bool, 3: ?string}
     */
    private function scoreInvoice(Invoice $invoice, string $collected, array $doctorRules, ?string $pct): array
    {
        $hasDoctor = $invoice->attending_doctor_id !== null;

        $invoicePct = $hasDoctor && $invoice->attending_doctor_commission_pct !== null
            ? (string) $invoice->attending_doctor_commission_pct
            : null;
        $invoiceAmount = $hasDoctor && $invoice->attending_doctor_commission_amount !== null
            ? (string) $invoice->attending_doctor_commission_amount
            : null;

        $hasConfig = $invoiceAmount !== null || $invoicePct !== null || $pct !== null || $doctorRules !== [];

        return [
            $this->invoiceShare($invoice, $collected, $doctorRules, $pct, $invoicePct, $invoiceAmount),
            $invoicePct,
            $hasConfig,
            $invoiceAmount,
        ];
    }

    /**
     * Doctor's share of one invoice: the sum of its lines' shares, so the
     * aggregate and the per-attention detail can never disagree. Invoices
     * without line items fall back to the doctor's global %.
     *
     * @param  array<int, array{type: string, value: string}>  $doctorRules
     */
    private function invoiceShare(
        Invoice $invoice,
        string $collected,
        array $doctorRules,
        ?string $pct,
        ?string $invoicePct = null,
        ?string $invoiceAmount = null,
    ): string {
        if ($invoice->items->isEmpty()) {
            // Per-attention FIXED amount wins: the doctor is paid FIRST, so they
            // take min(agreed amount, collected) and the clinic keeps the rest.
            if ($invoiceAmount !== null) {
                return bccomp($invoiceAmount, $collected, 6) === 1 ? $collected : $invoiceAmount;
            }
            if ($invoicePct !== null) {
                return bcmul($collected, bcdiv($invoicePct, '100', 6), 6);
            }

            return $pct !== null ? bcmul($collected, bcdiv($pct, '100', 6), 6) : '0';
        }

        $share = '0';
        foreach ($this->lineShares($invoice, $collected, $doctorRules, $pct, $invoicePct, $invoiceAmount) as $line) {
            $share = bcadd($share, $line, 6);
        }

        return $share;
    }

    /**
     * The doctor's raw (scale-6) share of each line of an invoice, in item order.
     *
     * A per-attention fixed amount or % agreed by the cashier applies to the
     * invoice as a whole, so it is spread across the lines in proportion to what
     * each one is worth. Otherwise every line is scored on its own rule (fixed
     * money per unit, a % of the line, or the doctor's global %), prorated by
     * how much of the invoice was collected in the period.
     *
     * @param  array<int, array{type: string, value: string}>  $doctorRules
     * @return list<string>
     */
    private function lineShares(
        Invoice $invoice,
        string $collected,
        array $doctorRules,
        ?string $pct,
        ?string $invoicePct = null,
        ?string $invoiceAmount = null,
    ): array {
        $items = $invoice->items->values();
        $weights = $items->map(fn ($item) => bcadd((string) $item->line_total, '0', 6))->all();

        if ($invoiceAmount !== null || $invoicePct !== null) {
            $total = $invoiceAmount !== null
                ? (bccomp($invoiceAmount, $collected, 6) === 1 ? $collected : $invoiceAmount)
                : bcmul($collected, bcdiv((string) $invoicePct, '100', 6), 6);

            return $this->spread($total, $weights);
        }

        $ratio = $this->ratio($collected, bcadd((string) $invoice->total, '0', 6));

        $shares = [];
        foreach ($items as $item) {
            $rule = $doctorRules[$item->medical_service_id] ?? null;

            if ($rule !== null && $rule['type'] === DoctorCommissionRule::TYPE_FIXED) {
                // Fixed money the doctor receives per unit, prorated by collection.
                $shares[] = bcmul(bcmul($rule['value'], (string) $item->quantity, 6), $ratio, 6);
            } elseif ($rule !== null) {
                // Percentage of the line, prorated by collection.
                $shares[] = bcmul(bcmul((string) $item->line_total, bcdiv($rule['value'], '100', 6), 6), $ratio, 6);
            } elseif ($pct !== null) {
                $shares[] = bcmul(bcmul((string) $item->line_total, bcdiv($pct, '100', 6), 6), $ratio, 6);
            } else {
                $shares[] = '0';
            }
        }

        return $shares;
    }

    /**
     * Split one amount across weights, proportionally (scale 6). Weightless
     * invoices (everything free) split evenly rather than dropping the money.
     *
     * @param  list<string>  $weights
     * @return list<string>
     */
    private function spread(string $amount, array $weights): array
    {
        $count = count($weights);
        if ($count === 0) {
            return [];
        }

        $sum = '0';
        foreach ($weights as $weight) {
            $sum = bcadd($sum, $weight, 6);
        }

        if (bccomp($sum, '0', 6) === 0) {
            $even = bcdiv($amount, (string) $count, 6);

            return array_fill(0, $count, $even);
        }

        return array_map(fn (string $w) => bcmul($amount, bcdiv($w, $sum, 6), 6), $weights);
    }

    /**
     * Round raw per-line amounts to cents so they add up EXACTLY to $target
     * (largest-remainder). Without this the detail table and the totals drift
     * apart by a cent or two and the whole report stops being trustworthy.
     *
     * @param  list<string>  $raw
     * @return list<string>
     */
    private function allocate(array $raw, string $target): array
    {
        $count = count($raw);
        if ($count === 0) {
            return [];
        }

        $targetCents = (int) round((float) $target * 100);

        $sumRaw = '0';
        foreach ($raw as $value) {
            $sumRaw = bcadd($sumRaw, $value, 6);
        }
        // Nothing to distribute proportionally (all-zero lines): give it to the first.
        if (bccomp($sumRaw, '0', 6) === 0) {
            $out = array_fill(0, $count, '0.00');
            $out[0] = bcadd((string) ($targetCents / 100), '0', 2);

            return $out;
        }

        $cents = [];
        $remainders = [];
        $assigned = 0;
        foreach ($raw as $i => $value) {
            // Proportional share of the target, floored to whole cents.
            $exact = ((float) $value / (float) $sumRaw) * $targetCents;
            $floor = (int) floor($exact);
            $cents[$i] = $floor;
            $remainders[$i] = $exact - $floor;
            $assigned += $floor;
        }

        // Hand the leftover cents to the biggest fractional parts.
        $order = array_keys($remainders);
        usort($order, fn ($a, $b) => $remainders[$b] <=> $remainders[$a]);
        $leftover = $targetCents - $assigned;
        for ($i = 0; $i < $leftover; $i++) {
            $cents[$order[$i % $count]]++;
        }

        return array_map(fn (int $c) => number_format($c / 100, 2, '.', ''), $cents);
    }

    /** Collected / total, capped at 1 (scale 6). */
    private function ratio(string $collected, string $total): string
    {
        if (bccomp($total, '0', 6) === 1) {
            $r = bcdiv($collected, $total, 6);

            return bccomp($r, '1', 6) === 1 ? '1' : $r;
        }

        return bccomp($collected, '0', 6) === 1 ? '1' : '0';
    }

    /**
     * Doctor global percentages in this workspace: [doctor_id => "60.00"].
     *
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function globalPercentages(int $workspaceId): Collection
    {
        return WorkspaceMember::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('doctor_id')
            ->whereNotNull('commission_pct')
            ->pluck('commission_pct', 'doctor_id');
    }

    /**
     * Per-service rules: [doctor_id][service_id => ['type' => .., 'value' => ..]].
     *
     * @return array<int, array<int, array{type: string, value: string}>>
     */
    private function allRules(int $workspaceId): array
    {
        $rules = [];
        foreach (DoctorCommissionRule::query()->where('workspace_id', $workspaceId)->get() as $rule) {
            $rules[$rule->doctor_id][$rule->medical_service_id] = [
                'type' => $rule->rule_type,
                'value' => (string) $rule->value,
            ];
        }

        return $rules;
    }

    /**
     * Agreed deductions per doctor in the window: [doctor_id => "25.00"].
     *
     * @return array<int, string>
     */
    private function adjustmentsByDoctor(int $workspaceId, ?Carbon $from, ?Carbon $to): array
    {
        return FinanceEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', FinanceEntry::TYPE_DOCTOR_ADJUSTMENT)
            ->whereNotNull('doctor_id')
            ->when($from, fn ($q) => $q->where('entry_date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->where('entry_date', '<=', $to->toDateString()))
            ->selectRaw('doctor_id, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('doctor_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->doctor_id => bcadd((string) $row->total, '0', 2)])
            ->all();
    }
}
