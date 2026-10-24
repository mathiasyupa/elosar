<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\FinanceEntry;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MedicalRecord;
use App\Models\Patient;
use App\Models\PatientImmunization;
use App\Models\Payment;
use App\Models\Pregnancy;
use App\Models\RecordDiagnosis;
use App\Models\RecordPrescription;
use App\Models\Specialty;
use App\Services\Obstetrics\GestationalAge;
use App\Services\Reports\DoctorSettlementService;
use App\Services\WorkspacePermissionService;
use App\Support\ActiveWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Financial reporting for the active workspace (Reportes screen). All figures
 * are WorkspaceScope-isolated and emitted as strings (bcmath / decimal:2).
 */
class ReportController extends Controller
{
    /**
     * Revenue summary: a monthly collected-revenue series for the last 6 months,
     * plus invoice totals grouped by status. `?months=` overrides the window
     * (1–24).
     */
    public function revenue(
        Request $request,
        ActiveWorkspace $activeWorkspace,
        DoctorSettlementService $settlement,
        WorkspacePermissionService $permissions,
    ): JsonResponse {
        $months = max(1, min(24, $request->integer('months', 6)));
        $start = Carbon::today()->startOfMonth()->subMonths($months - 1);

        // A clinical-only member (employed doctor) sees ONLY their own figures:
        // never the clinic's totals, a colleague's settlement or the margin.
        // Owner / admin / cashier see the whole clinic.
        $ownDoctorId = $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current());

        /** Scope invoice-derived queries to the member's own attentions. */
        $scopeInvoices = fn ($query) => $ownDoctorId
            ? $query->where('attending_doctor_id', $ownDoctorId)
            : $query;
        /** Same, for models that reach the invoice through a relation. */
        $scopeViaInvoice = fn ($query) => $ownDoctorId
            ? $query->whereHas('invoice', fn ($q) => $q->where('attending_doctor_id', $ownDoctorId))
            : $query;

        // Collected revenue per month (from payments).
        $rawMonthly = $scopeViaInvoice(Payment::query())
            ->where('payment_date', '>=', $start->toDateString())
            ->selectRaw("to_char(payment_date, 'YYYY-MM') AS ym, SUM(amount) AS total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        // Billed revenue per month (from invoices, by issue date) for the
        // billed-vs-collected comparison.
        $rawBilled = $scopeInvoices(Invoice::query())
            ->where('issued_at', '>=', $start->toDateString())
            ->selectRaw("to_char(issued_at, 'YYYY-MM') AS ym, SUM(total) AS total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $series = [];
        for ($i = 0; $i < $months; $i++) {
            $month = $start->copy()->addMonths($i);
            $key = $month->format('Y-m');
            $series[] = [
                'month' => $key,
                'label' => $month->locale('es')->translatedFormat('M'),
                'total' => bcadd((string) ($rawMonthly[$key] ?? '0'), '0', 2),
                'billed' => bcadd((string) ($rawBilled[$key] ?? '0'), '0', 2),
            ];
        }

        // Invoice totals grouped by status (issued/paid/partial/...).
        $byStatus = $scopeInvoices(Invoice::query())
            ->selectRaw('status, COUNT(*) AS count, COALESCE(SUM(total), 0) AS total')
            ->groupBy('status')
            ->get()
            ->map(fn ($row) => [
                'status' => $row->status,
                'count' => (int) $row->count,
                'total' => bcadd((string) $row->total, '0', 2),
            ]);

        $billedTotal = (string) ($scopeInvoices(Invoice::query())->sum('total') ?? '0');
        $collectedTotal = (string) ($scopeViaInvoice(Payment::query())->sum('amount') ?? '0');

        // Liquidación por médico: how much of what was collected belongs to each
        // attending doctor vs the clinic, applying per-service commission rules (or
        // the doctor's global %). Walk-ins without a doctor fold into "Sin asignar".
        // WorkspaceScope keeps the underlying invoices tenant-isolated.
        $byDoctor = $settlement->forWorkspace((int) $activeWorkspace->id());

        // An employed doctor only gets their own settlement row.
        if ($ownDoctorId) {
            $byDoctor = $byDoctor->filter(fn (array $row) => $row['doctor_id'] === $ownDoctorId)->values();
        }

        // Appointments by status (completed vs cancelled vs scheduled).
        $appointments = Appointment::query()
            ->when($ownDoctorId, fn ($q) => $q->where('doctor_id', $ownDoctorId))
            ->selectRaw('status, COUNT(*) AS count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // Top billed services by revenue (invoice line items). Money leads:
        // ordered by revenue, and each row carries its average price and its
        // share of ALL service revenue (concentration risk), not just of the top N.
        $serviceItems = fn () => InvoiceItem::query()
            ->whereHas('invoice', fn ($q) => $ownDoctorId ? $q->where('attending_doctor_id', $ownDoctorId) : $q);

        $servicesTotal = bcadd((string) ($serviceItems()->sum('line_total') ?? '0'), '0', 2);

        $topServices = $serviceItems()
            ->selectRaw('description, COUNT(*) AS count, SUM(line_total) AS total')
            ->groupBy('description')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(function ($row) use ($servicesTotal) {
                $total = bcadd((string) $row->total, '0', 2);
                $count = max(1, (int) $row->count);

                return [
                    'description' => $row->description,
                    'count' => (int) $row->count,
                    'total' => $total,
                    // Average price actually charged (list price minus discounts).
                    'avg' => bcdiv($total, (string) $count, 2),
                    // Share of all service revenue, 0–100 with one decimal.
                    'share' => bccomp($servicesTotal, '0', 2) > 0
                        ? bcdiv(bcmul($total, '100', 4), $servicesTotal, 1)
                        : '0.0',
                ];
            });

        return response()->json([
            'data' => [
                // 'own' = the figures are just this doctor's; 'clinic' = whole clinic.
                'scope' => $ownDoctorId ? 'own' : 'clinic',
                'monthly' => $series,
                'by_status' => $byStatus,
                'appointments' => [
                    'completed' => (int) ($appointments['completed'] ?? 0),
                    'cancelled' => (int) ($appointments['cancelled'] ?? 0),
                    'scheduled' => (int) ($appointments['scheduled'] ?? 0),
                ],
                'top_services' => $topServices,
                'services_total' => $servicesTotal,
                'by_doctor' => $byDoctor,
                'billed_total' => bcadd($billedTotal, '0', 2),
                'collected_total' => bcadd($collectedTotal, '0', 2),
                'outstanding_total' => bcsub(bcadd($billedTotal, '0', 2), bcadd($collectedTotal, '0', 2), 2),
            ],
        ]);
    }

    /**
     * Day-level finances for the selected period ("¿cómo va el día / el mes?").
     *
     * Answers the questions the monthly series cannot: what came in today and in
     * which payment methods (the end-of-day cash close), how each day of the
     * period performed, and whether that pace beats the previous one. The period
     * comes from `?from=&to=` (the shared selector of the Reportes screen) or
     * from `?month=YYYY-MM`, and defaults to the current month. A full calendar
     * month additionally gets its projection and its month-over-month cut. The
     * same self-scoping as revenue() applies, so an employed doctor only ever
     * sees their own money.
     */
    public function daily(
        Request $request,
        ActiveWorkspace $activeWorkspace,
        WorkspacePermissionService $permissions,
    ): JsonResponse {
        $ownDoctorId = $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current());

        $scopeInvoices = fn ($query) => $ownDoctorId
            ? $query->where('attending_doctor_id', $ownDoctorId)
            : $query;
        $scopeViaInvoice = fn ($query) => $ownDoctorId
            ? $query->whereHas('invoice', fn ($q) => $q->where('attending_doctor_id', $ownDoctorId))
            : $query;

        $today = Carbon::today();
        [$monthStart, $monthEnd, $isFullMonth] = $this->requestedRange($request, $today);
        $isCurrentMonth = $isFullMonth && $monthStart->isSameMonth($today);

        // Collected + billed per day of the selected period.
        $collectedByDay = $scopeViaInvoice(Payment::query())
            ->whereBetween('payment_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->selectRaw("to_char(payment_date, 'YYYY-MM-DD') AS d, SUM(amount) AS total, COUNT(*) AS count")
            ->groupBy('d')
            ->get()
            ->keyBy('d');

        $billedByDay = $scopeInvoices(Invoice::query())
            ->whereBetween('issued_at', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->selectRaw("to_char(issued_at, 'YYYY-MM-DD') AS d, SUM(total) AS total")
            ->groupBy('d')
            ->pluck('total', 'd');

        $days = [];
        $monthTotal = '0.00';
        $activeDays = 0;
        $cursor = $monthStart->copy();
        while ($cursor <= $monthEnd) {
            $key = $cursor->toDateString();
            $total = bcadd((string) ($collectedByDay[$key]->total ?? '0'), '0', 2);
            $monthTotal = bcadd($monthTotal, $total, 2);
            if (bccomp($total, '0', 2) > 0) {
                $activeDays++;
            }

            $days[] = [
                'date' => $key,
                'day' => $cursor->day,
                // 0 = Sunday … 6 = Saturday, matching Carbon::dayOfWeek.
                'weekday' => $cursor->dayOfWeek,
                'is_weekend' => $cursor->isWeekend(),
                // Days that haven't happened yet must render flat, not as a "$0 day".
                'is_future' => $cursor->gt($today),
                'is_today' => $cursor->isSameDay($today),
                'total' => $total,
                'billed' => bcadd((string) ($billedByDay[$key] ?? '0'), '0', 2),
                'count' => (int) ($collectedByDay[$key]->count ?? 0),
            ];
            $cursor->addDay();
        }

        return response()->json([
            'data' => [
                'scope' => $ownDoctorId ? 'own' : 'clinic',
                'month' => $monthStart->format('Y-m'),
                'from' => $monthStart->toDateString(),
                'to' => $monthEnd->toDateString(),
                'label' => $this->rangeLabel($monthStart, $monthEnd, $isFullMonth),
                'is_full_month' => $isFullMonth,
                'is_current_month' => $isCurrentMonth,
                'days' => $days,
                'summary' => $this->monthSummary($days, $monthTotal, $activeDays, $monthStart, $today, $isCurrentMonth),
                'by_weekday' => $this->weekdayAverages($days),
                'payment_methods' => $this->paymentMethods($scopeViaInvoice, $monthStart, $monthEnd),
                'today' => $this->todaySnapshot($scopeInvoices, $scopeViaInvoice, $ownDoctorId, $today),
                'comparison' => $isFullMonth
                    ? $this->monthComparison($scopeViaInvoice, $monthStart, $today, $isCurrentMonth, $monthTotal)
                    : $this->rangeComparison($scopeViaInvoice, $monthStart, $monthEnd, $monthTotal),
            ],
        ]);
    }

    // ── Daily report building blocks ─────────────────────────────────────────

    /**
     * The period the day-level report covers: `?from=&to=` (the shared period
     * selector) or `?month=YYYY-MM`, defaulting to the current month. Ranges are
     * clamped to a year so a wide selection can never build thousands of bars.
     *
     * @return array{0: Carbon, 1: Carbon, 2: bool} start, end, is-a-full-calendar-month
     */
    private function requestedRange(Request $request, Carbon $today): array
    {
        $from = $request->date('from');
        $to = $request->date('to');

        if ($from && $to) {
            $start = $from->copy()->startOfDay();
            $end = $to->copy()->startOfDay();
            if ($end->lt($start)) {
                [$start, $end] = [$end, $start];
            }
            if ($start->diffInDays($end) > 366) {
                $end = $start->copy()->addDays(366);
            }

            $isFullMonth = $start->isSameDay($start->copy()->startOfMonth())
                && $end->isSameDay($start->copy()->endOfMonth());

            return [$start, $end, $isFullMonth];
        }

        $month = $this->requestedMonth($request, $today);

        return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth(), true];
    }

    /** "julio 2026" for a whole month, "3 jul – 15 jul 2026" for a free range. */
    private function rangeLabel(Carbon $start, Carbon $end, bool $isFullMonth): string
    {
        if ($isFullMonth) {
            return $start->locale('es')->translatedFormat('F Y');
        }

        if ($start->isSameDay($end)) {
            return $start->locale('es')->translatedFormat('j \d\e F Y');
        }

        return $start->locale('es')->translatedFormat('j M').' – '.$end->locale('es')->translatedFormat('j M Y');
    }

    /** Parse `?month=YYYY-MM`, falling back to the current month. */
    private function requestedMonth(Request $request, Carbon $today): Carbon
    {
        $raw = (string) $request->query('month', '');

        if (preg_match('/^\d{4}-\d{2}$/', $raw) === 1) {
            try {
                return Carbon::createFromFormat('Y-m-d', $raw.'-01')->startOfMonth();
            } catch (\Exception) {
                // fall through to the current month
            }
        }

        return $today->copy()->startOfMonth();
    }

    /**
     * Month totals plus the two numbers that give a day its meaning: the daily
     * average (the bar to beat) and, for the month in progress, a straight-line
     * projection of where it closes at the current pace.
     *
     * @param  list<array<string, mixed>>  $days
     * @return array<string, mixed>
     */
    private function monthSummary(
        array $days,
        string $monthTotal,
        int $activeDays,
        Carbon $monthStart,
        Carbon $today,
        bool $isCurrentMonth,
    ): array {
        // Averaged over days that have actually happened — for the month in
        // progress that is today's day number, for a free range the days elapsed
        // inside it.
        $elapsed = max(1, count(array_filter($days, fn (array $day) => ! $day['is_future'])));

        $best = null;
        foreach ($days as $day) {
            if ($best === null || bccomp($day['total'], $best['total'], 2) > 0) {
                $best = $day;
            }
        }

        return [
            'collected' => $monthTotal,
            'active_days' => $activeDays,
            // Averaged over elapsed days (not just billing days) — that is the
            // pace the month is actually running at.
            'daily_avg' => bcdiv($monthTotal, (string) $elapsed, 2),
            'best_day' => $best !== null && bccomp($best['total'], '0', 2) > 0
                ? ['date' => $best['date'], 'total' => $best['total']]
                : null,
            'projection' => $isCurrentMonth
                ? bcmul(bcdiv($monthTotal, (string) $elapsed, 4), (string) $monthStart->copy()->endOfMonth()->day, 2)
                : null,
        ];
    }

    /**
     * Average collection per weekday across the month — surfaces the "your
     * Thursdays are half your Tuesdays" pattern that drives agenda decisions.
     * Future days are excluded so they don't drag the average down.
     *
     * @param  list<array<string, mixed>>  $days
     * @return list<array<string, mixed>>
     */
    private function weekdayAverages(array $days): array
    {
        $labels = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
        $totals = array_fill(0, 7, '0.00');
        $counts = array_fill(0, 7, 0);

        foreach ($days as $day) {
            if ($day['is_future']) {
                continue;
            }
            $w = $day['weekday'];
            $totals[$w] = bcadd($totals[$w], $day['total'], 2);
            $counts[$w]++;
        }

        $out = [];
        // Monday-first (1..6, then 0) — the way a clinic reads its week.
        foreach ([1, 2, 3, 4, 5, 6, 0] as $w) {
            $out[] = [
                'weekday' => $w,
                'label' => $labels[$w],
                'total' => $totals[$w],
                'avg' => $counts[$w] > 0 ? bcdiv($totals[$w], (string) $counts[$w], 2) : '0.00',
            ];
        }

        return $out;
    }

    /**
     * Collected amount per payment method over a window — the breakdown needed
     * to reconcile the cash drawer against the system.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentMethods(callable $scopeViaInvoice, Carbon $from, Carbon $to): array
    {
        return $scopeViaInvoice(Payment::query())
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('payment_method, COUNT(*) AS count, SUM(amount) AS total')
            ->groupBy('payment_method')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'method' => $row->payment_method,
                'count' => (int) $row->count,
                'total' => bcadd((string) $row->total, '0', 2),
            ])
            ->all();
    }

    /**
     * The end-of-day close: what was collected and billed today, in which
     * methods, how many patients were seen, and how today compares with the
     * average of the last 8 same weekdays (a Tuesday against Tuesdays).
     *
     * @return array<string, mixed>
     */
    private function todaySnapshot(
        callable $scopeInvoices,
        callable $scopeViaInvoice,
        ?int $ownDoctorId,
        Carbon $today,
    ): array {
        $collected = bcadd((string) ($scopeViaInvoice(Payment::query())
            ->whereDate('payment_date', $today)->sum('amount') ?? '0'), '0', 2);

        $invoicesToday = $scopeInvoices(Invoice::query())
            ->whereDate('issued_at', $today)
            ->whereNotIn('status', ['void', 'cancelled']);

        $billed = bcadd((string) ((clone $invoicesToday)->sum('total') ?? '0'), '0', 2);
        $invoiceCount = (clone $invoicesToday)->count();

        $attended = Appointment::query()
            ->when($ownDoctorId, fn ($q) => $q->where('doctor_id', $ownDoctorId))
            ->where('status', 'completed')
            ->whereDate('appointment_date', $today)
            ->count();

        // Same-weekday baseline: the previous 8 occurrences of today's weekday.
        $sameWeekdayDates = collect(range(1, 8))
            ->map(fn (int $n) => $today->copy()->subWeeks($n)->toDateString());

        $baselineTotal = bcadd((string) ($scopeViaInvoice(Payment::query())
            ->whereIn('payment_date', $sameWeekdayDates)->sum('amount') ?? '0'), '0', 2);

        return [
            'date' => $today->toDateString(),
            'label' => $today->locale('es')->translatedFormat('l j \d\e F'),
            'collected' => $collected,
            'billed' => $billed,
            'invoices' => $invoiceCount,
            'patients' => $attended,
            // Issued today but not yet collected.
            'pending' => bcsub($billed, $collected, 2),
            'weekday_avg' => bcdiv($baselineTotal, '8', 2),
        ];
    }

    /**
     * Same-point-in-the-month comparison: what the previous month had collected
     * by this same day number. Without it a monthly figure has nothing to be
     * measured against mid-month.
     *
     * @return array<string, mixed>
     */
    private function monthComparison(
        callable $scopeViaInvoice,
        Carbon $monthStart,
        Carbon $today,
        bool $isCurrentMonth,
        string $monthTotal,
    ): array {
        $previousStart = $monthStart->copy()->subMonth();
        // Cut the previous month at the same day number so we compare like with
        // like; clamp for short months (Feb 30 → Feb 28).
        $cutDay = $isCurrentMonth ? $today->day : $monthStart->copy()->endOfMonth()->day;
        $previousCut = $previousStart->copy()->addDays(min($cutDay, $previousStart->copy()->endOfMonth()->day) - 1);

        $previousToDate = bcadd((string) ($scopeViaInvoice(Payment::query())
            ->whereBetween('payment_date', [$previousStart->toDateString(), $previousCut->toDateString()])
            ->sum('amount') ?? '0'), '0', 2);

        $previousTotal = bcadd((string) ($scopeViaInvoice(Payment::query())
            ->whereBetween('payment_date', [
                $previousStart->toDateString(),
                $previousStart->copy()->endOfMonth()->toDateString(),
            ])->sum('amount') ?? '0'), '0', 2);

        return [
            'previous_month' => $previousStart->format('Y-m'),
            'previous_label' => $previousStart->locale('es')->translatedFormat('F'),
            'previous_to_date' => $previousToDate,
            'previous_total' => $previousTotal,
            'delta' => bcsub($monthTotal, $previousToDate, 2),
            'delta_pct' => bccomp($previousToDate, '0', 2) > 0
                ? bcdiv(bcmul(bcsub($monthTotal, $previousToDate, 2), '100', 4), $previousToDate, 1)
                : null,
        ];
    }

    /**
     * Same comparison for a free range: the window of the same length that ends
     * the day before it starts (last 7 days vs the 7 before them).
     *
     * @return array<string, mixed>
     */
    private function rangeComparison(
        callable $scopeViaInvoice,
        Carbon $start,
        Carbon $end,
        string $rangeTotal,
    ): array {
        $length = $start->diffInDays($end) + 1;
        $previousEnd = $start->copy()->subDay();
        $previousStart = $previousEnd->copy()->subDays($length - 1);

        $previousTotal = bcadd((string) ($scopeViaInvoice(Payment::query())
            ->whereBetween('payment_date', [$previousStart->toDateString(), $previousEnd->toDateString()])
            ->sum('amount') ?? '0'), '0', 2);

        return [
            'previous_month' => $previousStart->format('Y-m'),
            'previous_label' => $this->rangeLabel($previousStart, $previousEnd, false),
            // Equal-length windows, so "to date" and "total" are the same figure.
            'previous_to_date' => $previousTotal,
            'previous_total' => $previousTotal,
            'delta' => bcsub($rangeTotal, $previousTotal, 2),
            'delta_pct' => bccomp($previousTotal, '0', 2) > 0
                ? bcdiv(bcmul(bcsub($rangeTotal, $previousTotal, 2), '100', 4), $previousTotal, 1)
                : null,
        ];
    }

    // ── Settlement & P&L ─────────────────────────────────────────────────────

    /**
     * "Liquidación por médico" for a period: how much each attending doctor
     * earned, what the clinic kept, and the same figures opened up attention by
     * attention (one row per invoice line, the way a clinic audits its month).
     *
     * Filters: `?from=&to=` (defaults to the current month) and `?doctor_id=`.
     * An employed doctor is force-scoped to themselves — a colleague's payout is
     * not theirs to read.
     */
    public function settlement(
        Request $request,
        ActiveWorkspace $activeWorkspace,
        DoctorSettlementService $settlement,
        WorkspacePermissionService $permissions,
    ): JsonResponse {
        [$from, $to] = $this->periodRange($request);
        $ownDoctorId = $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current());
        $doctorId = $ownDoctorId ?? ($request->integer('doctor_id') ?: null);
        $workspaceId = (int) $activeWorkspace->id();

        $byDoctor = $settlement->forWorkspace($workspaceId, $from, $to);
        if ($doctorId !== null) {
            $byDoctor = $byDoctor->filter(fn (array $row) => $row['doctor_id'] === $doctorId)->values();
        }

        $detail = $settlement->detailForWorkspace($workspaceId, $from, $to, $doctorId);

        // What a doctor is paid is compensation, and the roster already treats
        // it that way (ClinicController::team() blanks commission_pct without
        // team.manage). This report handed the same numbers to the cash desk by
        // another door: `billing` holds reports.view, and selfScopedDoctorId()
        // only narrows clinical-only members, so a cashier read the whole clinic.
        //
        // The gate is finance.view rather than team.manage because that is what
        // this codebase already calls "the margin of the business" (see pnl()),
        // and doctor commission is its largest component. It also keeps the
        // admin whole: reading net profit without the cost that produces it
        // would be a report nobody can reconcile.
        //
        // A self-scoped doctor keeps their own figures — the rows are theirs.
        $seesCompensation = $ownDoctorId !== null
            || $permissions->can(Auth::user(), $activeWorkspace->current(), 'finance.view');

        $collected = '0.00';
        $gross = '0.00';
        $adjustments = '0.00';
        $doctorNet = '0.00';
        foreach ($byDoctor as $row) {
            $collected = bcadd($collected, $row['total'], 2);
            $gross = bcadd($gross, (string) ($row['doctor_amount'] ?? '0'), 2);
            $adjustments = bcadd($adjustments, $row['adjustments'], 2);
            $doctorNet = bcadd($doctorNet, (string) ($row['doctor_net'] ?? '0'), 2);
        }

        return response()->json([
            'data' => [
                'scope' => $ownDoctorId ? 'own' : 'clinic',
                // Lets the UI say "no ves el reparto" instead of rendering
                // columns of dashes that look like missing data.
                'shows_compensation' => $seesCompensation,
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'label' => $this->rangeLabel($from, $to, $this->isFullMonth($from, $to)),
                ],
                'totals' => [
                    // What was collected per doctor stays: reconciling the cash
                    // drawer against who attended is the cashier's actual job.
                    'collected' => $collected,
                    // Before agreed deductions…
                    'doctor_amount' => $seesCompensation ? $gross : null,
                    'adjustments' => $seesCompensation ? $adjustments : null,
                    // …and what the doctor actually takes home.
                    'doctor_net' => $seesCompensation ? $doctorNet : null,
                    'clinic_amount' => $seesCompensation ? bcsub($collected, $doctorNet, 2) : null,
                    'attentions' => $detail->count(),
                    'invoices' => $detail->pluck('invoice_id')->unique()->count(),
                    'patients' => $detail->pluck('patient_name')->unique()->count(),
                ],
                'by_doctor' => $seesCompensation
                    ? $byDoctor
                    : $byDoctor->map(fn (array $row) => $this->withoutCompensation($row))->values(),
                'detail' => $seesCompensation
                    ? $detail
                    : $detail->map(fn (array $row) => $this->withoutCompensation($row))->values(),
            ],
        ]);
    }

    /**
     * Blanks the compensation figures of one settlement row.
     *
     * Nulled rather than dropped: the shape of the payload stays the same for
     * every caller, so the client renders one table instead of two, and a
     * missing key can never be mistaken for a zero.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withoutCompensation(array $row): array
    {
        foreach (['commission_pct', 'doctor_amount', 'doctor_net', 'clinic_amount', 'adjustments'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = null;
            }
        }

        return $row;
    }

    /**
     * The clinic's books for a period ("¿cuánto quedó realmente?"):
     *
     *   cobrado + otros ingresos − comisiones a médicos − gastos = utilidad neta
     *
     * Gated by finance.view (owner / admin only), so the cashier never receives
     * the margin of the business and an employed doctor never gets here at all.
     */
    public function pnl(
        Request $request,
        ActiveWorkspace $activeWorkspace,
        DoctorSettlementService $settlement,
        WorkspacePermissionService $permissions,
    ): JsonResponse {
        // finance.view is never granted to a clinical-only member; double-locked
        // because this is the one endpoint that exposes the whole margin.
        abort_if(
            $permissions->selfScopedDoctorId(Auth::user(), $activeWorkspace->current()) !== null,
            403,
            'No autorizado.',
        );

        [$from, $to] = $this->periodRange($request);
        $workspaceId = (int) $activeWorkspace->id();

        $collected = bcadd((string) (Payment::query()
            ->whereBetween('payment_date', [$from->toDateString(), $to->toDateString()])
            ->sum('amount') ?? '0'), '0', 2);

        $billed = bcadd((string) (Invoice::query()
            ->whereNotIn('status', ['void', 'cancelled'])
            ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()])
            ->sum('total') ?? '0'), '0', 2);

        $otherIncome = FinanceEntry::totalFor(
            FinanceEntry::TYPE_INCOME, $from->toDateString(), $to->toDateString()
        );
        $expenses = FinanceEntry::totalFor(
            FinanceEntry::TYPE_EXPENSE, $from->toDateString(), $to->toDateString()
        );

        // Commissions net of the deductions agreed with each doctor.
        $commissions = '0.00';
        $adjustments = '0.00';
        foreach ($settlement->forWorkspace($workspaceId, $from, $to) as $row) {
            $commissions = bcadd($commissions, (string) ($row['doctor_net'] ?? '0'), 2);
            $adjustments = bcadd($adjustments, $row['adjustments'], 2);
        }

        $income = bcadd($collected, $otherIncome, 2);
        $netProfit = bcsub(bcsub($income, $commissions, 2), $expenses, 2);

        // What is still owed to the clinic, whenever it was billed.
        $receivable = bcadd((string) (Invoice::query()
            ->whereNotIn('status', ['void', 'cancelled'])
            ->selectRaw('COALESCE(SUM(total - COALESCE(amount_paid, 0)), 0) AS due')
            ->value('due') ?? '0'), '0', 2);

        return response()->json([
            'data' => [
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                    'label' => $this->rangeLabel($from, $to, $this->isFullMonth($from, $to)),
                ],
                'collected' => $collected,
                'billed' => $billed,
                'other_income' => $otherIncome,
                'income_total' => $income,
                'doctor_commissions' => $commissions,
                'doctor_adjustments' => $adjustments,
                'expenses' => $expenses,
                'net_profit' => $netProfit,
                'net_margin_pct' => bccomp($income, '0', 2) > 0
                    ? bcdiv(bcmul($netProfit, '100', 4), $income, 1)
                    : '0.0',
                'receivable_total' => $receivable,
                'expenses_by_category' => $this->entriesByCategory(FinanceEntry::TYPE_EXPENSE, $from, $to),
                'income_by_category' => $this->entriesByCategory(FinanceEntry::TYPE_INCOME, $from, $to),
                'top_services' => $this->topServices($from, $to),
                'services_by_category' => $this->servicesByCategory($from, $to),
                // Trend independent of the selected period: the six months ending
                // with it, so a one-month selection still has something to be read
                // against.
                'series' => $this->pnlSeries($settlement, $workspaceId, $to),
            ],
        ]);
    }

    /**
     * `?from=&to=` for the period-driven reports, defaulting to the current
     * month (the Reportes screen opens on it).
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodRange(Request $request): array
    {
        $from = $request->date('from');
        $to = $request->date('to');

        if (! $from || ! $to) {
            $today = Carbon::today();

            return [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()];
        }

        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();

        return $end->lt($start) ? [$end, $start] : [$start, $end];
    }

    /** Whether a range is exactly one calendar month. */
    private function isFullMonth(Carbon $start, Carbon $end): bool
    {
        return $start->isSameDay($start->copy()->startOfMonth())
            && $end->isSameDay($start->copy()->endOfMonth());
    }

    /**
     * Hand-typed money grouped by category, biggest first.
     *
     * @return list<array<string, mixed>>
     */
    private function entriesByCategory(string $type, Carbon $from, Carbon $to): array
    {
        return FinanceEntry::query()
            ->where('type', $type)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('category, COUNT(*) AS count, COALESCE(SUM(amount), 0) AS total')
            ->groupBy('category')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'category' => $row->category,
                'count' => (int) $row->count,
                'total' => bcadd((string) $row->total, '0', 2),
            ])
            ->all();
    }

    /**
     * What the clinic actually sells, by revenue in the period: the answer to
     * "¿qué paga esta clínica?". `share` is the % of ALL service revenue, so a
     * concentration risk is visible even outside the top rows.
     *
     * @return list<array<string, mixed>>
     */
    private function topServices(Carbon $from, Carbon $to): array
    {
        $items = fn () => InvoiceItem::query()->whereHas(
            'invoice',
            fn ($q) => $q
                ->whereNotIn('status', ['void', 'cancelled'])
                ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()]),
        );

        $total = bcadd((string) ($items()->sum('line_total') ?? '0'), '0', 2);

        return [
            'total' => $total,
            'rows' => $items()
                ->selectRaw('description, COUNT(*) AS count, SUM(line_total) AS total')
                ->groupBy('description')
                ->orderByDesc('total')
                ->limit(8)
                ->get()
                ->map(function ($row) use ($total) {
                    $rowTotal = bcadd((string) $row->total, '0', 2);
                    $count = max(1, (int) $row->count);

                    return [
                        'description' => $row->description,
                        'count' => (int) $row->count,
                        'total' => $rowTotal,
                        'avg' => bcdiv($rowTotal, (string) $count, 2),
                        'share' => bccomp($total, '0', 2) > 0
                            ? bcdiv(bcmul($rowTotal, '100', 4), $total, 1)
                            : '0.0',
                    ];
                })
                ->all(),
        ];
    }

    /**
     * Billed revenue grouped by the arancel's category — the "¿cuánto entró por
     * laboratorio?" question, which top_services (grouped by free-text
     * description) cannot answer.
     *
     * Deliberately BILLED, not collected: payments are recorded against the
     * whole invoice, never per line, so splitting a partial payment across
     * lines would be invention. Collected-by-method already lives in the daily
     * cash close and is a different question.
     *
     * Lines typed by hand carry no medical_service_id and land in "Sin
     * categoría", which is never hidden nor spread across the others: a large
     * bucket there IS the finding — the arancel catalogue is not being used.
     *
     * Named services_by_category and not income_by_category, which already
     * exists and means something else entirely (hand-typed FinanceEntry "otros
     * ingresos" — room rental and the like).
     *
     * @return array{total: string, rows: list<array<string, mixed>>}
     */
    private function servicesByCategory(Carbon $from, Carbon $to): array
    {
        $items = fn () => InvoiceItem::query()->whereHas(
            'invoice',
            fn ($q) => $q
                ->whereNotIn('status', ['void', 'cancelled'])
                ->whereBetween('issued_at', [$from->toDateString(), $to->toDateString()]),
        );

        $total = bcadd((string) ($items()->sum('line_total') ?? '0'), '0', 2);

        $rows = $items()
            ->leftJoin('medical_services', 'invoice_items.medical_service_id', '=', 'medical_services.id')
            ->selectRaw('medical_services.category AS category, COUNT(*) AS count, SUM(invoice_items.line_total) AS total')
            ->groupBy('medical_services.category')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) use ($total) {
                $rowTotal = bcadd((string) $row->total, '0', 2);

                return [
                    // Null category = invoiced without picking an arancel.
                    'category' => $row->category,
                    'count' => (int) $row->count,
                    'total' => $rowTotal,
                    'share' => bccomp($total, '0', 2) > 0
                        ? bcdiv(bcmul($rowTotal, '100', 4), $total, 1)
                        : '0.0',
                ];
            })
            ->all();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * Six months of income vs what left the clinic — the shape of the business,
     * not just this month's number.
     *
     * @return list<array<string, mixed>>
     */
    private function pnlSeries(DoctorSettlementService $settlement, int $workspaceId, Carbon $end): array
    {
        $series = [];
        $cursor = $end->copy()->startOfMonth()->subMonths(5);

        for ($i = 0; $i < 6; $i++) {
            $monthStart = $cursor->copy()->startOfMonth();
            $monthEnd = $cursor->copy()->endOfMonth();
            $from = $monthStart->toDateString();
            $to = $monthEnd->toDateString();

            $collected = bcadd((string) (Payment::query()
                ->whereBetween('payment_date', [$from, $to])->sum('amount') ?? '0'), '0', 2);

            $commissions = '0.00';
            foreach ($settlement->forWorkspace($workspaceId, $monthStart, $monthEnd) as $row) {
                $commissions = bcadd($commissions, (string) ($row['doctor_net'] ?? '0'), 2);
            }

            $otherIncome = FinanceEntry::totalFor(FinanceEntry::TYPE_INCOME, $from, $to);
            $expenses = FinanceEntry::totalFor(FinanceEntry::TYPE_EXPENSE, $from, $to);
            $income = bcadd($collected, $otherIncome, 2);

            $series[] = [
                'month' => $monthStart->format('Y-m'),
                'label' => $monthStart->locale('es')->translatedFormat('M'),
                'collected' => $collected,
                'other_income' => $otherIncome,
                'income_total' => $income,
                'commissions' => $commissions,
                'expenses' => $expenses,
                'net_profit' => bcsub(bcsub($income, $commissions, 2), $expenses, 2),
            ];

            $cursor->addMonth();
        }

        return $series;
    }

    /**
     * Clinical / epidemiological report ("Vig. de Salud"): morbidity, production,
     * demographics, prescribing, vaccine reminders and probable delivery dates.
     *
     * Everything is WorkspaceScope-isolated — child tables (diagnoses,
     * prescriptions) reach isolation through whereHas on the scoped MedicalRecord,
     * so no manual workspace_id filtering is needed. Gated by reports.clinical
     * (clinical audience) so the cashier (billing) never receives diagnoses.
     * Filters: ?from=&to=&doctor_id=.
     */
    public function clinical(Request $request): JsonResponse
    {
        [$from, $to, $doctorId] = $this->period($request);

        // Shared filter for the scoped parent record (completed attentions only).
        $recordFilter = function ($q) use ($from, $to, $doctorId): void {
            $q->where('status', 'completed')
                ->whereBetween('created_at', [$from, $to])
                ->when($doctorId, fn ($q, $d) => $q->where('created_by', $d));
        };

        $report = [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'morbidity' => $this->morbidity($recordFilter),
            'by_system' => $this->morbidityBySystem($recordFilter),
            'attentions' => $this->attentionsSummary($from, $to, $doctorId),
            'demographics' => $this->demographics($from, $to, $doctorId),
            'medications' => $this->topMedications($recordFilter),
            'vaccine_reminders' => $this->vaccineReminders(),
        ];

        // This worklist exposes pregnancy dates and patient contact details.
        // Keep the general clinical report usable, but omit this specialty-only
        // surface for doctors without the approved capability.
        if (Auth::user()?->doctor?->hasSpecialtyCapability(Specialty::CAPABILITY_GYNECO_OBSTETRICS)) {
            // The FPP worklist belongs to the shared patient, not to the author
            // of one visit. Pregnancies have no responsible-doctor column, so
            // keeping it clinic-wide is the only consistent behavior for both
            // registered pregnancies and legacy FUMs.
            $report['probable_deliveries'] = $this->probableDeliveries();
        }

        return response()->json(['data' => $report]);
    }

    /**
     * "Detalle de casos atendidos": paginated line-item list of completed
     * attentions (date, patient, doctor, type, primary diagnosis). Lists
     * patient-level PII, hence reports.clinical.
     */
    public function attentions(Request $request): JsonResponse
    {
        [$from, $to, $doctorId] = $this->period($request);

        $paginator = MedicalRecord::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($doctorId, fn ($q, $d) => $q->where('created_by', $d))
            ->with(['patient:id,name,id_number', 'doctor:id,name', 'diagnoses'])
            ->orderByDesc('created_at')
            ->paginate(30);

        $rows = collect($paginator->items())->map(function (MedicalRecord $r) {
            $primary = $r->diagnoses->firstWhere('type', 'primary') ?? $r->diagnoses->first();

            return [
                'id' => $r->id,
                'date' => $r->created_at?->toDateString(),
                'patient' => $r->patient?->name,
                'id_number' => $r->patient?->id_number,
                'doctor' => $r->doctor?->name,
                'type' => $r->consultation_type ?? 'general',
                'diagnosis' => $primary ? trim($primary->cie10_code.' — '.$primary->cie10_description) : null,
            ];
        });

        return response()->json([
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    // ── Clinical report building blocks ──────────────────────────────────────

    /**
     * Resolve the reporting window and optional doctor filter.
     * Defaults to the last 6 calendar months through today.
     *
     * @return array{0: Carbon, 1: Carbon, 2: ?int}
     */
    private function period(Request $request): array
    {
        $to = ($request->date('to') ?? Carbon::today())->endOfDay();
        $from = ($request->date('from') ?? Carbon::today()->subMonths(6)->startOfMonth())->startOfDay();
        $doctorId = $request->integer('doctor_id') ?: null;

        return [$from, $to, $doctorId];
    }

    /** Top ICD-10 diagnoses (principales patologías). */
    private function morbidity(callable $recordFilter): Collection
    {
        return RecordDiagnosis::query()
            ->whereHas('medicalRecord', $recordFilter)
            ->selectRaw('cie10_code, cie10_description, COUNT(*) AS total')
            ->groupBy('cie10_code', 'cie10_description')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'code' => $r->cie10_code,
                'description' => $r->cie10_description,
                'total' => (int) $r->total,
            ]);
    }

    /** Diagnoses grouped by ICD-10 chapter/system (patologías por aparato afecto). */
    private function morbidityBySystem(callable $recordFilter): Collection
    {
        return RecordDiagnosis::query()
            ->whereHas('medicalRecord', $recordFilter)
            ->leftJoin('cie10_codes', 'record_diagnoses.cie10_code', '=', 'cie10_codes.code')
            ->selectRaw("COALESCE(cie10_codes.category, 'Sin clasificar') AS system, COUNT(*) AS total")
            ->groupBy('system')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['system' => $r->system, 'total' => (int) $r->total]);
    }

    /** Top prescribed medications. */
    private function topMedications(callable $recordFilter): Collection
    {
        return RecordPrescription::query()
            ->whereHas('medicalRecord', $recordFilter)
            ->selectRaw("COALESCE(NULLIF(generic_name, ''), medication_name) AS medication, COUNT(*) AS total, SUM(CASE WHEN is_controlled THEN 1 ELSE 0 END) AS controlled")
            ->groupBy('medication')
            ->orderByDesc('total')
            ->limit(15)
            ->get()
            ->map(fn ($r) => [
                'medication' => $r->medication,
                'total' => (int) $r->total,
                'controlled' => (int) $r->controlled,
            ]);
    }

    /**
     * Production summary: total attentions, monthly series, by consultation type
     * and by attending doctor.
     *
     * @return array<string, mixed>
     */
    private function attentionsSummary(Carbon $from, Carbon $to, ?int $doctorId): array
    {
        // Columns are table-qualified because the by_doctor variant joins the
        // doctors table (which also has status/created_at/created_by columns).
        $base = fn () => MedicalRecord::query()
            ->where('medical_records.status', 'completed')
            ->whereBetween('medical_records.created_at', [$from, $to])
            ->when($doctorId, fn ($q, $d) => $q->where('medical_records.created_by', $d));

        $rawMonthly = $base()
            ->selectRaw("to_char(created_at, 'YYYY-MM') AS ym, COUNT(*) AS total")
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $series = [];
        $cursor = $from->copy()->startOfMonth();
        $lastMonth = $to->copy()->startOfMonth();
        while ($cursor <= $lastMonth) {
            $key = $cursor->format('Y-m');
            $series[] = [
                'month' => $key,
                'label' => $cursor->locale('es')->translatedFormat('M'),
                'total' => (int) ($rawMonthly[$key] ?? 0),
            ];
            $cursor->addMonth();
        }

        $byType = $base()
            ->selectRaw("COALESCE(consultation_type, 'general') AS type, COUNT(*) AS total")
            ->groupBy('type')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'total' => (int) $r->total]);

        $byDoctor = $base()
            ->leftJoin('doctors', 'medical_records.created_by', '=', 'doctors.id')
            ->selectRaw('medical_records.created_by AS doctor_id, doctors.name AS doctor_name, COUNT(*) AS total')
            ->groupBy('medical_records.created_by', 'doctors.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => [
                'doctor_id' => $r->doctor_id ? (int) $r->doctor_id : null,
                'name' => $r->doctor_name ?? 'Sin asignar',
                'total' => (int) $r->total,
            ]);

        return [
            'total' => $base()->count(),
            'monthly' => $series,
            'by_type' => $byType,
            'by_doctor' => $byDoctor,
        ];
    }

    /**
     * Patient demographics for the whole workspace population plus new vs
     * returning patients within the period (based on their first attention).
     *
     * @return array<string, mixed>
     */
    private function demographics(Carbon $from, Carbon $to, ?int $doctorId): array
    {
        $byGender = Patient::query()
            ->selectRaw("COALESCE(NULLIF(gender, ''), 'no_especificado') AS gender, COUNT(*) AS total")
            ->groupBy('gender')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($r) => ['gender' => $r->gender, 'total' => (int) $r->total]);

        $byAge = Patient::query()
            ->selectRaw(<<<'SQL'
                CASE
                    WHEN birth_date IS NULL THEN 'Sin fecha'
                    WHEN date_part('year', age(birth_date)) < 1 THEN '< 1'
                    WHEN date_part('year', age(birth_date)) < 5 THEN '1-4'
                    WHEN date_part('year', age(birth_date)) < 10 THEN '5-9'
                    WHEN date_part('year', age(birth_date)) < 15 THEN '10-14'
                    WHEN date_part('year', age(birth_date)) < 20 THEN '15-19'
                    WHEN date_part('year', age(birth_date)) < 40 THEN '20-39'
                    WHEN date_part('year', age(birth_date)) < 65 THEN '40-64'
                    ELSE '65+'
                END AS age_group, COUNT(*) AS total
            SQL)
            ->groupBy('age_group')
            ->get()
            ->map(fn ($r) => ['group' => $r->age_group, 'total' => (int) $r->total]);

        $byCity = Patient::query()
            ->selectRaw("COALESCE(NULLIF(city, ''), 'Sin ciudad') AS city, COUNT(*) AS total")
            ->groupBy('city')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['city' => $r->city, 'total' => (int) $r->total]);

        // New vs returning: patients attended in the period, split by whether
        // their FIRST-ever (doctor-filtered) attention falls inside the window.
        $attended = MedicalRecord::query()
            ->where('status', 'completed')
            ->whereBetween('created_at', [$from, $to])
            ->when($doctorId, fn ($q, $d) => $q->where('created_by', $d))
            ->distinct()
            ->pluck('patient_id');

        $new = 0;
        $returning = 0;
        if ($attended->isNotEmpty()) {
            $firstAt = MedicalRecord::query()
                ->where('status', 'completed')
                ->when($doctorId, fn ($q, $d) => $q->where('created_by', $d))
                ->whereIn('patient_id', $attended)
                ->selectRaw('patient_id, MIN(created_at) AS first_at')
                ->groupBy('patient_id')
                ->pluck('first_at', 'patient_id');

            foreach ($firstAt as $firstAtValue) {
                Carbon::parse($firstAtValue)->between($from, $to) ? $new++ : $returning++;
            }
        }

        return [
            'by_gender' => $byGender,
            'by_age_group' => $byAge,
            'by_city' => $byCity,
            'new_patients' => $new,
            'returning_patients' => $returning,
        ];
    }

    /**
     * Vaccine reminders due soon or overdue (Recordatorio de Vacunas).
     *
     * @return array<string, mixed>
     */
    private function vaccineReminders(): array
    {
        $today = Carbon::today();

        $items = PatientImmunization::query()
            ->whereNotNull('reminder_at')
            ->whereBetween('reminder_at', [$today->copy()->subMonth(), $today->copy()->addMonths(2)])
            ->with('patient:id,name,id_number')
            ->orderBy('reminder_at')
            ->limit(50)
            ->get()
            ->map(fn (PatientImmunization $i) => [
                'patient_id' => $i->patient_id,
                'patient' => $i->patient?->name,
                'vaccine' => $i->vaccine_name,
                'dose' => $i->doseLabelText(),
                'reminder_at' => $i->reminder_at?->toDateString(),
                'overdue' => $i->reminder_at !== null && $i->reminder_at->lt($today),
            ]);

        return ['count' => $items->count(), 'items' => $items->values()];
    }

    /**
     * Probable delivery dates (Fechas Probables de Parto).
     *
     * Hybrid on purpose, with a precedence. A registered pregnancy is the source
     * of truth: it carries the dating the clinician chose and a due date the
     * server derived. Consultations that predate `pregnancies` are still read
     * from `structured_exam->>'fum'`, but ONLY for patients with no pregnancy on
     * file — otherwise a woman with both would appear twice.
     *
     * Nothing is written or rewritten here. Closed clinical records keep their
     * JSONB exactly as it was; migrating a patient forward is a decision her
     * doctor makes, from the screen, one at a time. `origin` is what lets the UI
     * offer that.
     *
     * @return array<string, mixed>
     */
    private function probableDeliveries(): array
    {
        $today = Carbon::today();
        $cutoff = $today->copy()->subWeeks(2); // still ongoing: EDD from ~2 weeks ago onward

        $items = collect();
        $covered = [];

        // 1. Registered pregnancies.
        $pregnancies = Pregnancy::query()
            ->where('status', 'active')
            ->whereNotNull('edd_date')
            ->where('edd_date', '>=', $cutoff->toDateString())
            ->with('patient:id,name,id_number,birth_date,phone')
            ->get();

        foreach ($pregnancies as $pregnancy) {
            $covered[$pregnancy->patient_id] = true;

            $age = GestationalAge::at($pregnancy->datingInput(), $today);

            $items->push([
                'patient_id' => $pregnancy->patient_id,
                'patient' => $pregnancy->patient?->name,
                'age' => $pregnancy->patient?->birth_date?->age,
                'phone' => $pregnancy->patient?->phone,
                'fum' => $pregnancy->lmp_date?->toDateString(),
                'edd' => $pregnancy->edd_date->toDateString(),
                'weeks' => $age['weeks'],
                'dating' => $age['source'],
                'origin' => 'pregnancy',
            ]);
        }

        // 2. The legacy path, for patients who have no pregnancy registered yet.
        // Bounded to the last 12 months of records before the JSONB scan: a
        // record documenting a still-relevant FUM (edd = fum + 280 days >=
        // cutoff) was, in every realistic case, itself written within the
        // pregnancy — a full 9-month pregnancy plus margin. Without this bound,
        // this ran a JSONB text-extraction scan over the workspace's entire
        // history on every load of this report.
        $records = MedicalRecord::query()
            ->where('created_at', '>=', $today->copy()->subMonths(12))
            ->whereRaw("COALESCE(structured_exam->>'fum', '') <> ''")
            ->orderByDesc('created_at')
            ->with('patient:id,name,id_number,birth_date,phone')
            ->get(['id', 'patient_id', 'created_by', 'created_at', 'structured_exam']);

        $seen = [];
        foreach ($records as $r) {
            if (isset($covered[$r->patient_id]) || isset($seen[$r->patient_id])) {
                continue; // records are date-desc, so the first seen is the latest FUM
            }
            $fumRaw = $r->structured_exam['fum'] ?? null;
            if (! $fumRaw) {
                continue;
            }
            try {
                $fum = Carbon::parse($fumRaw)->startOfDay();
            } catch (\Exception) {
                continue;
            }
            $seen[$r->patient_id] = true;

            $edd = $fum->copy()->addDays(280);
            if ($edd->lt($cutoff)) {
                continue;
            }

            $items->push([
                'patient_id' => $r->patient_id,
                'patient' => $r->patient?->name,
                'age' => $r->patient?->birth_date?->age,
                'phone' => $r->patient?->phone,
                'fum' => $fum->toDateString(),
                'edd' => $edd->toDateString(),
                // Through the service, so both paths count weeks the same way —
                // the old inline intdiv() silently dropped the days.
                'weeks' => GestationalAge::at(
                    ['lmp_date' => $fum->toDateString(), 'dating_source' => 'lmp'],
                    $today
                )['weeks'],
                'dating' => 'lmp',
                'origin' => 'legacy_exam',
            ]);
        }

        $items = $items->sortBy('edd')->values();

        return ['count' => $items->count(), 'items' => $items];
    }
}
