import { useCallback, useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Icon } from '@/components/Icon';

/**
 * The one period the whole Reportes screen answers for.
 *
 * Every panel (day by day, settlement, books) reads the same window, so the
 * figures on screen always reconcile with each other — the report used to mix
 * three different time frames at once. It lives in the URL (`?from=&to=`) so a
 * reload, a tab switch or a shared link keeps the same period.
 */

const PRESETS = [
  { key: 'today', label: 'Hoy' },
  { key: 'week', label: 'Esta semana' },
  { key: 'month', label: 'Este mes' },
  { key: 'prev-month', label: 'Mes anterior' },
] as const;

export type PeriodPreset = (typeof PRESETS)[number]['key'] | 'custom';

export interface Period {
  from: string;
  to: string;
}

const MONTHS = [
  'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
  'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre',
];

const pad = (n: number) => String(n).padStart(2, '0');

/** YYYY-MM-DD in LOCAL time — toISOString() would shift the day in Ecuador. */
export function ymd(date: Date): string {
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

/** The dates of a preset window. */
export function presetRange(preset: PeriodPreset): Period {
  const now = new Date();
  const y = now.getFullYear();
  const m = now.getMonth();

  switch (preset) {
    case 'today':
      return { from: ymd(now), to: ymd(now) };
    case 'week': {
      // The WHOLE week, Monday to Sunday — same idea as "Este mes", which is the
      // whole month. Cutting it at today collapsed Monday's "esta semana" into a
      // single day, which is exactly the day view next to it.
      const monday = new Date(y, m, now.getDate() - ((now.getDay() + 6) % 7));
      const sunday = new Date(y, m, monday.getDate() + 6);
      return { from: ymd(monday), to: ymd(sunday) };
    }
    case 'prev-month':
      return { from: ymd(new Date(y, m - 1, 1)), to: ymd(new Date(y, m, 0)) };
    case 'month':
    default:
      return { from: ymd(new Date(y, m, 1)), to: ymd(new Date(y, m + 1, 0)) };
  }
}

/** Which preset a window corresponds to, or 'custom'. */
function detectPreset(period: Period): PeriodPreset {
  for (const { key } of PRESETS) {
    const range = presetRange(key);
    if (range.from === period.from && range.to === period.to) return key;
  }
  return 'custom';
}

/** "julio 2026", "12 de julio de 2026" or "3 jul – 18 jul 2026". */
export function periodLabel({ from, to }: Period): string {
  const [fy, fm, fd] = from.split('-').map(Number);
  const [ty, tm, td] = to.split('-').map(Number);
  if (!fy || !ty) return '';

  const isFullMonth = fd === 1 && fy === ty && fm === tm && td === new Date(ty, tm, 0).getDate();
  if (isFullMonth) return `${MONTHS[fm - 1]} ${fy}`;
  if (from === to) return `${fd} de ${MONTHS[fm - 1]} de ${fy}`;

  const short = (mm: number) => MONTHS[mm - 1].slice(0, 3);
  return `${fd} ${short(fm)} – ${td} ${short(tm)} ${ty}`;
}

/** Whether the window is exactly one calendar month (drives the month-only bits). */
export function isFullMonth({ from, to }: Period): boolean {
  const [fy, fm, fd] = from.split('-').map(Number);
  const [ty, tm, td] = to.split('-').map(Number);
  return fd === 1 && fy === ty && fm === tm && td === new Date(ty, tm, 0).getDate();
}

/** Shift a full-month window by n months (the day-by-day panel steps with it). */
export function shiftMonth({ from }: Period, n: number): Period {
  const [y, m] = from.split('-').map(Number);
  const start = new Date(y, (m ?? 1) - 1 + n, 1);
  return { from: ymd(start), to: ymd(new Date(start.getFullYear(), start.getMonth() + 1, 0)) };
}

const parseYmd = (value: string) => {
  const [y, m, d] = value.split('-').map(Number);
  return new Date(y, (m ?? 1) - 1, d ?? 1);
};

const DAY_MS = 86_400_000;
const daysBetween = (a: Date, b: Date) => Math.round((b.getTime() - a.getTime()) / DAY_MS);

/**
 * What a period is fairly compared against: the window just before it, of
 * the same shape (the previous month for a month, the previous seven days
 * for a week). While the period is still running, only the same stretch of
 * the previous one counts — eleven days of October against all of September
 * would always read as a drop.
 */
export function comparisonWindow(period: Period, today: Date = new Date()): { period: Period; label: string } {
  const from = parseYmd(period.from);
  const to = parseYmd(period.to);

  let prevFrom: Date;
  let prevTo: Date;
  if (isFullMonth(period)) {
    prevFrom = new Date(from.getFullYear(), from.getMonth() - 1, 1);
    prevTo = new Date(from.getFullYear(), from.getMonth(), 0);
  } else {
    const length = daysBetween(from, to) + 1;
    prevFrom = new Date(from.getFullYear(), from.getMonth(), from.getDate() - length);
    prevTo = new Date(from.getFullYear(), from.getMonth(), from.getDate() - 1);
  }

  const now = new Date(today.getFullYear(), today.getMonth(), today.getDate());
  const running = now >= from && now < to;
  if (running) {
    const clipped = new Date(prevFrom.getFullYear(), prevFrom.getMonth(), prevFrom.getDate() + daysBetween(from, now));
    if (clipped < prevTo) prevTo = clipped;
  }

  const prev = { from: ymd(prevFrom), to: ymd(prevTo) };
  // A month names itself; any other window shows its (already clipped) dates.
  if (!isFullMonth(period)) return { period: prev, label: periodLabel(prev) };
  const month = MONTHS[prevFrom.getMonth()];
  return { period: prev, label: running ? `${month} a la misma fecha` : month };
}

/**
 * The active period, read from the URL and defaulting to the current month.
 * Setting it preserves every other query param (the open tab, for instance).
 */
export function usePeriod(): {
  period: Period;
  label: string;
  preset: PeriodPreset;
  setPeriod: (next: Period) => void;
} {
  const [searchParams, setSearchParams] = useSearchParams();

  const from = searchParams.get('from');
  const to = searchParams.get('to');

  const period = useMemo<Period>(
    () => (from && to ? { from, to } : presetRange('month')),
    [from, to],
  );

  const setPeriod = useCallback(
    (next: Period) => {
      const params = new URLSearchParams(searchParams);
      params.set('from', next.from);
      params.set('to', next.to);
      setSearchParams(params, { replace: true });
    },
    [searchParams, setSearchParams],
  );

  return {
    period,
    label: periodLabel(period),
    preset: detectPreset(period),
    setPeriod,
  };
}

/** Preset chips + the two dates. Everything below it answers for this window. */
export function PeriodBar({
  period,
  preset,
  label,
  onChange,
}: {
  period: Period;
  preset: PeriodPreset;
  label: string;
  onChange: (next: Period) => void;
}) {
  return (
    <div className="period-bar">
      <span className="pb-icon">
        <Icon name="cal" size={16} />
      </span>
      <div className="pill-row">
        {PRESETS.map((p) => (
          <button
            key={p.key}
            type="button"
            className={`pill ${preset === p.key ? 'active' : ''}`}
            onClick={() => onChange(presetRange(p.key))}
          >
            {p.label}
          </button>
        ))}
      </div>

      <div className="pb-dates">
        <input
          type="date"
          className="field"
          aria-label="Desde"
          value={period.from}
          max={period.to}
          onChange={(e) => e.target.value && onChange({ ...period, from: e.target.value })}
        />
        <span className="muted">–</span>
        <input
          type="date"
          className="field"
          aria-label="Hasta"
          value={period.to}
          min={period.from}
          onChange={(e) => e.target.value && onChange({ ...period, to: e.target.value })}
        />
      </div>

      <span className="pb-label">{label}</span>
    </div>
  );
}
