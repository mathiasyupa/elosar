import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchDailyReport } from '@/api/reports';
import type { DailyReport } from '@/api/types';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { KpiMini } from '@/components/ui/Kpi';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { formatMoney, t } from '@/i18n/es';
import { ExcelButton } from '@/components/ui/ExcelButton';
import { stampedFilename } from '@/lib/excel';
import { dailySheets } from './exports';
import { useChartWidth } from './useChartWidth';
import { QueryError } from '@/components/ui/QueryError';
import { isFullMonth, presetRange, shiftMonth, type Period } from './PeriodBar';

const METHOD_LABELS: Record<string, string> = {
  cash: 'Efectivo',
  transfer: 'Transferencia',
  card: 'Tarjeta',
  other: 'Otro',
};

const WEEKDAY_NAMES = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];

const METHOD_TONES: Record<string, string> = {
  cash: 'green',
  transfer: 'blue',
  card: 'violet',
  other: 'muted',
};

const MONTH_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];

/** Round up to a "nice" axis ceiling (1/2/5 × 10^n). */
function niceCeil(n: number): number {
  if (n <= 0) return 100;
  const pow = 10 ** Math.floor(Math.log10(n));
  const unit = n / pow;
  const nice = unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10;
  return nice * pow;
}

/** Compact $ label for chart axes ($850, $1.2k, $45k). */
function compactMoney(n: number): string {
  if (n >= 10_000) return `$${Math.round(n / 1000)}k`;
  if (n >= 1000) return `$${(n / 1000).toFixed(1).replace(/\.0$/, '')}k`;
  return `$${Math.round(n)}`;
}

/** Signed percentage with its arrow, e.g. "▲ 18,4%". */
function DeltaBadge({ pct, sub }: { pct: string | null; sub: string }) {
  if (pct === null) return <span className="tk-delta muted">{sub}</span>;
  const value = Number(pct);
  const dir = value >= 0 ? 'up' : 'down';
  return (
    <span className={`tk-delta ${dir}`}>
      <Icon name="arrow" size={12} />
      {`${value >= 0 ? '+' : ''}${value.toFixed(1).replace('.', ',')}%`} <span className="muted">{sub}</span>
    </span>
  );
}

/**
 * One bar per day of the month, with the daily-average line as the reference
 * to beat. Weekends are muted, today is highlighted, and days that haven't
 * happened yet render flat instead of as a "$0 day". Hovering a bar shows its
 * figures — the chart is meant to be read by pointing at it, not just looked at.
 */
export function DailyBars({ days, avg }: { days: DailyReport['days']; avg: string }) {
  const [hover, setHover] = useState<number | null>(null);

  const [wrapRef, W] = useChartWidth();
  const rawMax = Math.max(0, ...days.map((d) => Number(d.total)));
  const max = niceCeil(rawMax);
  // Follows the container width so the chart draws 1:1 at any monitor size.
  const H = 224;
  const padL = 52;
  const padT = 12;
  const padB = 24;
  const plotW = W - padL - 12;
  const plotH = H - padT - padB;
  const slot = plotW / Math.max(1, days.length);
  const y = (v: number) => padT + plotH - (v / max) * plotH;
  const avgY = y(Number(avg));

  const hovered = hover !== null ? days[hover] : null;
  const longRange = days.length > 62;

  const fill = (d: DailyReport['days'][number], i: number) => {
    if (hover === i) return 'var(--accent-700)';
    if (d.is_today) return 'var(--accent)';
    if (d.is_weekend) return 'color-mix(in srgb, var(--accent) 28%, transparent)';
    return 'color-mix(in srgb, var(--accent) 62%, transparent)';
  };

  return (
    // The scroll lives on the outside so the tooltip, positioned in % of the
    // chart, still lands on the right bar once the chart is scrolled.
    <div className="chart-scroll" ref={wrapRef}>
    <div className="chart-wrap" onMouseLeave={() => setHover(null)}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} role="img" aria-label="Cobrado por día del mes">
        {[0, max / 2, max].map((v) => (
          <g key={v}>
            <line
              x1={padL}
              x2={W - 6}
              y1={y(v)}
              y2={y(v)}
              stroke="var(--border)"
              strokeWidth="1"
              strokeDasharray={v === 0 ? undefined : '3 4'}
            />
            <text x={padL - 8} y={y(v) + 3.5} textAnchor="end" fontSize="10.5" fill="var(--text-3)">
              {compactMoney(v)}
            </text>
          </g>
        ))}

        {/* The pace line: what an average day of this month looks like. */}
        {Number(avg) > 0 && (
          <g>
            <line x1={padL} x2={W - 6} y1={avgY} y2={avgY} stroke="var(--amber)" strokeWidth="1.5" strokeDasharray="5 4" />
            <text x={W - 6} y={avgY - 5} textAnchor="end" fontSize="10.5" fontWeight="700" fill="var(--amber)">
              promedio {compactMoney(Number(avg))}
            </text>
          </g>
        )}

        {days.map((d, i) => {
          const value = Number(d.total);
          const bx = padL + i * slot + slot * 0.16;
          const bw = Math.max(2, slot * 0.68);
          // Over a long range only each month's first day is labelled, with the month:
          // «1 5 10 15…» repeated ten times was unreadable.
          const showLabel = longRange ? d.day === 1 : d.day === 1 || d.day % 5 === 0;
          return (
            <g key={d.date} onMouseEnter={() => setHover(i)}>
              {/* Full-height hit area so thin bars (and $0 days) stay hoverable. */}
              <rect x={padL + i * slot} y={padT} width={slot} height={plotH} fill="transparent" />
              {d.is_future ? (
                <rect x={bx} y={padT + plotH - 2} width={bw} height={2} rx={1} fill="var(--border)" />
              ) : (
                <rect
                  x={bx}
                  y={value > 0 ? y(value) : padT + plotH - 2}
                  width={bw}
                  height={value > 0 ? Math.max(2, padT + plotH - y(value)) : 2}
                  rx={2.5}
                  fill={value > 0 ? fill(d, i) : 'var(--border)'}
                />
              )}
              {showLabel && (
                <text x={bx + bw / 2} y={H - 7} textAnchor="middle" fontSize="10.5" fill="var(--text-3)">
                  {longRange ? MONTH_SHORT[Number(d.date.slice(5, 7)) - 1] : d.day}
                </text>
              )}
            </g>
          );
        })}
      </svg>

      {hovered && (
        <div
          className="chart-tip"
          style={{
            left: `${((padL + days.indexOf(hovered) * slot + slot / 2) / W) * 100}%`,
            top: `${(Math.min(y(Number(hovered.total)), avgY) / H) * 100}%`,
          }}
        >
          <b>{`${hovered.day} · ${['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'][hovered.weekday]}`}</b>
          {hovered.is_future ? (
            <span className="muted">Aún no ocurre</span>
          ) : (
            <>
              <span>{formatMoney(hovered.total)} cobrado</span>
              <span className="muted">
                {hovered.count === 0
                  ? 'sin cobros'
                  : `${hovered.count} ${hovered.count === 1 ? 'cobro' : 'cobros'} · ${formatMoney(hovered.billed)} facturado`}
              </span>
            </>
          )}
        </div>
      )}
    </div>
    </div>
  );
}

/** Stacked share bar + list: how the month's money actually came in. */
function PaymentMethods({ methods, total }: { methods: DailyReport['payment_methods']; total: string }) {
  const sum = Number(total);
  if (methods.length === 0) {
    return <p className="rbar-empty">Sin cobros registrados en el período.</p>;
  }

  return (
    <div className="stack-sm">
      <svg className="split-svg" viewBox="0 0 100 12" preserveAspectRatio="none" role="img" aria-label="Reparto por forma de pago">
        <rect className="split-bg" x="0" y="0" width="100" height="12" />
        {methods.reduce<{ x: number; els: JSX.Element[] }>(
          (acc, m) => {
            const w = sum > 0 ? (Number(m.total) / sum) * 100 : 0;
            acc.els.push(
              <rect key={m.method} className={`sw-fill-${METHOD_TONES[m.method] ?? 'muted'}`} x={acc.x} y="0" width={w} height="12">
                <title>{`${METHOD_LABELS[m.method] ?? m.method}: ${formatMoney(m.total)}`}</title>
              </rect>,
            );
            return { x: acc.x + w, els: acc.els };
          },
          { x: 0, els: [] },
        ).els}
      </svg>
      <div className="legend-list">
        {methods.map((m) => (
          <div key={m.method} className="legend-item">
            <i className={`sw sw-${METHOD_TONES[m.method] ?? 'muted'}`} />
            <span className="ll-label">{METHOD_LABELS[m.method] ?? m.method}</span>
            <span className="muted">
              {sum > 0 ? `${Math.round((Number(m.total) / sum) * 100)}%` : '—'} · {m.count}
            </span>
            <b className="mono">{formatMoney(m.total)}</b>
          </div>
        ))}
      </div>
    </div>
  );
}

/** Average collection per weekday — the agenda signal hiding in the month. */
function WeekdayStrip({ rows }: { rows: DailyReport['by_weekday'] }) {
  const max = Math.max(1, ...rows.map((r) => Number(r.avg)));
  const best = rows.reduce((b, r) => (Number(r.avg) > Number(b.avg) ? r : b), rows[0]);

  return (
    <div className="weekday-strip">
      {rows.map((r) => {
        const value = Number(r.avg);
        return (
          <div key={r.weekday} className={`wd-col ${r.weekday === best?.weekday && value > 0 ? 'best' : ''}`}>
            <div className="wd-track" title={`${r.label}: ${formatMoney(r.avg)} en promedio`}>
              <svg className="wd-svg" viewBox="0 0 10 100" preserveAspectRatio="none" aria-hidden="true">
                <rect className="wd-bar" x="0" y={100 - Math.max(2, (value / max) * 100)} width="10" height={Math.max(2, (value / max) * 100)} />
              </svg>
            </div>
            <span className="wd-label">{r.label}</span>
            <span className="wd-val mono">{compactMoney(value)}</span>
          </div>
        );
      })}
    </div>
  );
}

/**
 * The day-and-period block of the finance report: the end-of-day cash close on
 * top (what came in today, in which methods, against a normal same-weekday),
 * then the selected period day by day with its pace, projection and weekday
 * pattern. The period is owned by the screen; a full calendar month can also be
 * stepped from here.
 */
export function DailyFinancePanel({
  period,
  onPeriodChange,
}: {
  period: Period;
  onPeriodChange: (next: Period) => void;
}) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const thisMonth = presetRange('month');
  const monthly = isFullMonth(period);

  const { data, isPending, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['reports', 'daily', activeWorkspaceId, period.from, period.to],
    queryFn: () => fetchDailyReport(period),
    // Keep the previous period on screen while the next one loads, so stepping
    // through months never collapses the whole block into a spinner.
    placeholderData: (previous) => previous,
  });

  if (isPending) return <Spinner label={t.common.loading} />;
  if (isError || isPaused || !data)
    return (
      <QueryError error={error} area="el cierre del día" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
    );

  const { today, summary, comparison } = data;

  // The panel takes the shape of the period instead of always drawing the same
  // blocks: a single day has no "day by day" chart, and a week has no weekday
  // pattern to average — its seven bars already ARE the pattern.
  const dayCount = data.days.length;
  const isSingleDay = dayCount === 1;
  const includesToday = data.days.some((d) => d.is_today);
  const showWeekdayStrip = dayCount >= 21;
  const singleDay = data.days[0];

  return (
    <div className={`stack ${isFetching ? 'is-refetching' : ''}`}>
      <div className="panel-toolbar">
        <span className="muted">
          {isSingleDay
            ? 'El cierre del día: qué entró y cómo te pagaron.'
            : 'Lo cobrado en el período, día por día.'}
        </span>
        <div className="row wrap push-right">
          <ExcelButton
            filename={stampedFilename('cobros-dia-a-dia')}
            label="Descargar Excel"
            build={() => dailySheets(data)}
          />
        </div>
      </div>

      {/* ── The headline: the period, not always "today" ───────────── */}
      <Card padded={false} className="today-hero">
        <div className="th-main">
          <span className="th-eyebrow">
            <Icon name="clock" size={13} /> {data.label}
          </span>
          <b className="th-value">{formatMoney(summary.collected)}</b>
          <span className="th-caption">{isSingleDay ? 'cobrado en el día' : 'cobrado en el período'}</span>
          {isSingleDay && includesToday ? (
            // Today against a normal same weekday reads better than against
            // yesterday, which may well have been a Sunday.
            <DeltaBadge
              pct={
                Number(today.weekday_avg) > 0
                  ? String(((Number(today.collected) - Number(today.weekday_avg)) / Number(today.weekday_avg)) * 100)
                  : null
              }
              sub={
                Number(today.weekday_avg) > 0
                  ? `vs. ${formatMoney(today.weekday_avg)} de un ${WEEKDAY_NAMES[new Date().getDay()]} normal`
                  : 'sin historial para comparar'
              }
            />
          ) : (
            <DeltaBadge
              pct={comparison.delta_pct}
              sub={`vs. ${formatMoney(comparison.previous_to_date)} de ${comparison.previous_label}${
                data.is_current_month ? ' a la misma fecha' : ''
              }`}
            />
          )}
        </div>
        <div className="th-stats">
          {isSingleDay ? (
            <>
              <div className="th-stat">
                <span>Cobros del día</span>
                <b>{singleDay?.count ?? 0}</b>
              </div>
              <div className="th-stat">
                <span>Facturado</span>
                <b className="mono">{formatMoney(singleDay?.billed ?? '0')}</b>
              </div>
              {includesToday && (
                <>
                  <div className="th-stat">
                    <span>Pacientes atendidos</span>
                    <b>{today.patients}</b>
                  </div>
                  <div className={`th-stat ${Number(today.pending) > 0 ? 'warn' : ''}`}>
                    <span>Queda por cobrar</span>
                    <b className="mono">{formatMoney(today.pending)}</b>
                  </div>
                </>
              )}
            </>
          ) : (
            <>
              <div className="th-stat">
                <span>Promedio diario</span>
                <b className="mono">{formatMoney(summary.daily_avg)}</b>
              </div>
              <div className="th-stat">
                <span>Días con cobros</span>
                <b>{summary.active_days}</b>
              </div>
              {summary.best_day && (
                <div className="th-stat">
                  <span>Mejor día</span>
                  <b className="mono">{formatMoney(summary.best_day.total)}</b>
                </div>
              )}
              {summary.projection ? (
                <div className="th-stat">
                  <span>Proyección de cierre</span>
                  <b className="mono">{formatMoney(summary.projection)}</b>
                </div>
              ) : (
                <div className="th-stat">
                  <span>{comparison.previous_label}</span>
                  <b className="mono">{formatMoney(comparison.previous_total)}</b>
                </div>
              )}
            </>
          )}
        </div>
      </Card>

      {/* Today's close, when the period is wider than today but contains it. */}
      {!isSingleDay && includesToday && (
        <div className="grid g-3">
          <KpiMini
            icon="money"
            label="Cobrado hoy"
            value={formatMoney(today.collected)}
            tone="green"
            sub={
              Number(today.weekday_avg) > 0
                ? `un ${WEEKDAY_NAMES[new Date().getDay()]} normal: ${formatMoney(today.weekday_avg)}`
                : 'sin historial para comparar'
            }
          />
          <KpiMini
            icon="users"
            label="Citas completadas hoy"
            value={String(today.patients)}
            tone="blue"
            sub={`${today.invoices} ${today.invoices === 1 ? 'factura emitida' : 'facturas emitidas'}`}
          />
          <KpiMini
            icon="card"
            label="Queda por cobrar de hoy"
            value={formatMoney(today.pending)}
            tone={Number(today.pending) > 0 ? 'amber' : 'green'}
            sub={`${formatMoney(today.billed)} facturado hoy`}
          />
        </div>
      )}

      {/* ── The period, day by day ─────────────────────────────────── */}
      {!isSingleDay && (
        <Card padded={false}>
          <div className="card-head">
            <div className="month-nav">
              {monthly && (
                <button
                  type="button"
                  className="icon-btn"
                  aria-label="Mes anterior"
                  onClick={() => onPeriodChange(shiftMonth(period, -1))}
                >
                  <Icon name="chevron" size={16} className="chev-left" />
                </button>
              )}
              <h3 className="month-title">{data.label}</h3>
              {monthly && (
                <button
                  type="button"
                  className="icon-btn"
                  aria-label="Mes siguiente"
                  disabled={period.from >= thisMonth.from}
                  onClick={() => onPeriodChange(shiftMonth(period, 1))}
                >
                  <Icon name="chevron" size={16} className="chev-right" />
                </button>
              )}
              {monthly && period.from !== thisMonth.from && (
                <button type="button" className="btn ghost sm" onClick={() => onPeriodChange(thisMonth)}>
                  Este mes
                </button>
              )}
            </div>
            <span className="sub">cobrado por día</span>
          </div>

          <div className="card-pad">
            <DailyBars days={data.days} avg={summary.daily_avg} />
          </div>
        </Card>
      )}

      <div className={showWeekdayStrip ? 'grid g-2' : ''}>
        <Card padded={false}>
          <div className="card-head">
            <h3>Cómo te pagaron</h3>
            <span className="sub">{data.label}</span>
          </div>
          <div className="card-pad">
            <PaymentMethods methods={data.payment_methods} total={summary.collected} />
          </div>
        </Card>

        {/* Averaging weekdays needs several of each: pointless on a day or a week. */}
        {showWeekdayStrip && (
          <Card padded={false}>
            <div className="card-head">
              <h3>Tu semana</h3>
              <span className="sub">promedio cobrado por día</span>
            </div>
            <div className="card-pad">
              <WeekdayStrip rows={data.by_weekday} />
            </div>
          </Card>
        )}
      </div>
    </div>
  );
}
