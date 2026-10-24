import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchPnlReport } from '@/api/reports';
import { deleteFinanceEntry, fetchFinanceEntries } from '@/api/finance';
import type { FinanceEntry, PnlReport } from '@/api/types';
import { useAuthStore } from '@/store/auth';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { QueryError } from '@/components/ui/QueryError';
import { ExcelButton } from '@/components/ui/ExcelButton';
import { Icon } from '@/components/Icon';
import { stampedFilename } from '@/lib/excel';
import { formatDate, formatMoney, t } from '@/i18n/es';
import { useChartWidth } from './useChartWidth';
import { CATEGORY_LABELS, FinanceEntryModal } from './FinanceEntryModal';
import { pnlSheets } from './exports';
import type { Period } from './PeriodBar';
import { ReportBars } from './ReportBars';

/** Arancel categories (English in the schema, Spanish on screen). Distinct from
 *  CATEGORY_LABELS above, which names hand-typed FinanceEntry movements. */
const SERVICE_CATEGORY_LABELS: Record<string, string> = {
  general: 'General',
  laboratory: 'Laboratorio',
  imaging: 'Imagenología',
  procedure: 'Procedimientos',
  dental: 'Odontología',
};

const TYPE_LABELS: Record<FinanceEntry['type'], string> = {
  expense: 'Gasto',
  income: 'Otro ingreso',
  doctor_adjustment: 'Descuento a médico',
};

function compactMoney(n: number): string {
  const abs = Math.abs(n);
  const sign = n < 0 ? '-' : '';
  if (abs >= 10_000) return `${sign}$${Math.round(abs / 1000)}k`;
  if (abs >= 1000) return `${sign}$${(abs / 1000).toFixed(1).replace(/\.0$/, '')}k`;
  return `${sign}$${Math.round(abs)}`;
}

function niceCeil(n: number): number {
  if (n <= 0) return 100;
  const pow = 10 ** Math.floor(Math.log10(n));
  const unit = n / pow;
  const nice = unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10;
  return nice * pow;
}

/**
 * The result, read left to right the way it happens: everything collected, minus
 * what belongs to the specialists, minus what the clinic spent — and what is
 * left over.
 */
function Waterfall({ data }: { data: PnlReport }) {
  const net = Number(data.net_profit);
  const steps = [
    { label: 'Cobrado', value: data.collected, tone: 'in', icon: 'money' },
    { label: 'Otros ingresos', value: data.other_income, tone: 'in', icon: 'plus' },
    { label: 'A especialistas', value: data.doctor_commissions, tone: 'out', icon: 'user' },
    { label: 'Gastos', value: data.expenses, tone: 'out', icon: 'card' },
  ];

  return (
    <Card padded={false} className={`pnl-hero ${net < 0 ? 'negative' : ''}`}>
      <div className="pnl-net">
        <span className="pnl-eyebrow">Utilidad neta · {data.period.label}</span>
        <b className="pnl-value">{formatMoney(data.net_profit)}</b>
        <span className="pnl-margin">
          {`${Number(data.net_margin_pct).toFixed(1).replace('.', ',')}% de margen sobre ${formatMoney(
            data.income_total,
          )} de ingresos`}
        </span>
      </div>

      <div className="pnl-steps">
        {steps.map((step) => (
          <div key={step.label} className={`wf-step ${step.tone}`}>
            <span className="wf-label">
              <Icon name={step.icon} size={13} /> {step.label}
            </span>
            <b className="mono">
              {step.tone === 'out' ? '− ' : ''}
              {formatMoney(step.value)}
            </b>
          </div>
        ))}
      </div>
    </Card>
  );
}

/** Six months of income against what left the clinic, with the net on top. */
function TrendChart({ series }: { series: PnlReport['series'] }) {
  const [wrapRef, W] = useChartWidth();

  const max = niceCeil(
    Math.max(
      0,
      ...series.map((s) => Number(s.income_total)),
      ...series.map((s) => Number(s.commissions) + Number(s.expenses)),
    ) || 1,
  );
  const H = 240;
  const padL = 52;
  const padT = 14;
  const padB = 26;
  const plotW = W - padL - 12;
  const plotH = H - padT - padB;
  const slot = plotW / Math.max(1, series.length);
  const y = (v: number) => padT + plotH - (Math.max(0, v) / max) * plotH;

  const netPoints = series
    .map((s, i) => `${padL + i * slot + slot / 2},${y(Number(s.net_profit))}`)
    .join(' ');

  return (
    <div className="chart-scroll" ref={wrapRef}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} role="img" aria-label="Ingresos, egresos y utilidad por mes">
        {[0, max / 2, max].map((v) => (
          <g key={v}>
            <line
              x1={padL}
              x2={W - 4}
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

        {series.map((s, i) => {
          const bw = Math.min(34, slot * 0.3);
          const gap = 5;
          const bx = padL + i * slot + (slot - (bw * 2 + gap)) / 2;
          const income = Number(s.income_total);
          const commissions = Number(s.commissions);
          const expenses = Number(s.expenses);
          const outTop = y(commissions + expenses);

          return (
            <g key={s.month}>
              <title>
                {`${s.label} — Ingresos: ${formatMoney(s.income_total)} · A médicos: ${formatMoney(
                  s.commissions,
                )} · Gastos: ${formatMoney(s.expenses)} · Neto: ${formatMoney(s.net_profit)}`}
              </title>
              <rect
                x={bx}
                y={y(income)}
                width={bw}
                height={Math.max(0, plotH + padT - y(income))}
                rx={3.5}
                fill="var(--accent-soft)"
              />
              {/* Egresos apilados: comisión abajo, gasto arriba. */}
              <rect
                x={bx + bw + gap}
                y={y(commissions)}
                width={bw}
                height={Math.max(0, plotH + padT - y(commissions))}
                rx={3.5}
                fill="var(--amber)"
              />
              <rect
                x={bx + bw + gap}
                y={outTop}
                width={bw}
                height={Math.max(0, y(commissions) - outTop)}
                rx={3.5}
                fill="var(--red)"
              />
              <text x={padL + i * slot + slot / 2} y={H - 8} textAnchor="middle" fontSize="11" fill="var(--text-3)">
                {s.label}
              </text>
            </g>
          );
        })}

        <polyline points={netPoints} fill="none" stroke="var(--accent)" strokeWidth="2.5" />
        {series.map((s, i) => (
          <circle
            key={s.month}
            cx={padL + i * slot + slot / 2}
            cy={y(Number(s.net_profit))}
            r={3.5}
            fill="var(--surface)"
            stroke="var(--accent)"
            strokeWidth="2.5"
          />
        ))}
      </svg>
    </div>
  );
}

/** Where the money went, by category. */
function CategoryBars({ rows }: { rows: PnlReport['expenses_by_category'] }) {
  return (
    <div className="card-pad">
      <ReportBars
        tone="amber"
        label="Gastos por categoría"
        empty="Sin gastos registrados en el período."
        data={rows.map((row) => ({
          label: CATEGORY_LABELS[row.category] ?? row.category,
          hint: String(row.count),
          value: Number(row.total),
          display: formatMoney(row.total),
        }))}
      />
    </div>
  );
}

/**
 * "Ingresos y egresos": the clinic's books for the period — what it really made
 * once the specialists and the costs are paid — plus the place to type in the
 * money the invoicing flow cannot know about.
 */
export function ProfitLossPanel({ period, label }: { period: Period; label: string }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const { can } = useWorkspaceCapabilities();
  const queryClient = useQueryClient();
  const canManage = can('finance.manage');

  const [editing, setEditing] = useState<FinanceEntry | null>(null);
  const [creating, setCreating] = useState(false);

  const pnl = useQuery({
    queryKey: ['reports', 'pnl', activeWorkspaceId, period.from, period.to],
    queryFn: () => fetchPnlReport(period),
    placeholderData: (previous) => previous,
  });

  const entries = useQuery({
    queryKey: ['finance-entries', activeWorkspaceId, period.from, period.to],
    queryFn: () => fetchFinanceEntries(period),
    placeholderData: (previous) => previous,
  });

  const refresh = () => {
    void queryClient.invalidateQueries({ queryKey: ['reports'] });
    void queryClient.invalidateQueries({ queryKey: ['finance-entries'] });
  };

  const remove = useMutation({
    mutationFn: (id: number) => deleteFinanceEntry(id),
    onSuccess: refresh,
  });

  if (pnl.isPending) return <Spinner label={t.common.loading} />;
  if (pnl.isError || pnl.isPaused || !pnl.data)
    return (
      <QueryError
        error={pnl.error}
        area="el estado de resultados"
        onRetry={() => void pnl.refetch()}
        retrying={pnl.isFetching}
        paused={pnl.isPaused}
      />
    );

  const data = pnl.data;
  const rows = entries.data?.data ?? [];

  return (
    <div className={`stack ${pnl.isFetching ? 'is-refetching' : ''}`}>
      <div className="panel-toolbar">
        <span className="muted">
          Cobrado + otros ingresos − comisiones a especialistas − gastos.
        </span>
        <div className="row wrap push-right">
          <ExcelButton
            filename={stampedFilename('ingresos-egresos')}
            label="Descargar Excel"
            build={() => pnlSheets(data, rows)}
          />
          {canManage && (
            <button className="btn primary sm" type="button" onClick={() => setCreating(true)}>
              <Icon name="plus" size={15} /> Registrar movimiento
            </button>
          )}
        </div>
      </div>

      <Waterfall data={data} />

      <div className="grid g-2 report-analysis-grid">
        <Card padded={false}>
          <div className="card-head">
            <div>
              <h3>Cómo viene el negocio</h3>
              <span className="sub">últimos seis meses</span>
            </div>
            <div className="chart-legend">
              <span><i className="sw sw-accent-soft" /> Ingresos</span>
              <span><i className="sw sw-amber" /> A médicos</span>
              <span><i className="sw sw-red" /> Gastos</span>
              <span><i className="sw sw-accent" /> Utilidad</span>
            </div>
          </div>
          <div className="card-pad">
            <TrendChart series={data.series} />
          </div>
        </Card>

        <Card padded={false}>
          <div className="card-head">
            <h3>En qué se fue</h3>
            <span className="sub">gastos por categoría</span>
          </div>
          <CategoryBars rows={data.expenses_by_category} />
        </Card>
      </div>

      <Card padded={false}>
        <div className="card-head">
          <div>
            <h3>Facturado por categoría</h3>
            {/* "Facturado" y no "cobrado" a propósito: los pagos se registran
                contra la factura completa, nunca por línea, así que repartir un
                abono parcial entre categorías sería inventar. El desglose de lo
                cobrado por método vive en el cierre de caja. */}
            <span className="sub">{`facturado en ${label} · lo cobrado por método está en el cierre de caja`}</span>
          </div>
        </div>
        <div className="card-pad stack-sm">
          <ReportBars
            label="Facturado por categoría"
            empty="Sin servicios facturados en el período."
            data={data.services_by_category.rows.map((row) => ({
              label: row.category ? SERVICE_CATEGORY_LABELS[row.category] ?? row.category : 'Sin categoría',
              hint: `${row.count} ${row.count === 1 ? 'línea' : 'líneas'} · ${row.share} %`,
              value: Number(row.total),
              display: formatMoney(row.total),
            }))}
          />
          {data.services_by_category.rows.some((r) => r.category === null) && (
            // Not spread or hidden: if this row is large, that IS the finding —
            // invoices are being typed by hand instead of picked from the catalog.
            <p className="rbar-empty">«Sin categoría» son líneas escritas a mano, sin arancel del catálogo.</p>
          )}
        </div>
      </Card>

      <Card padded={false}>
        <div className="card-head">
          <h3>Servicios que más ingresan</h3>
          <span className="sub">{`facturado en ${label}`}</span>
        </div>
        <div className="card-pad">
          <ReportBars
            label="Servicios que más ingresan"
            empty="Sin servicios facturados en el período."
            data={data.top_services.rows.map((service) => ({
              label: service.description,
              hint: `${service.count} × ${formatMoney(service.avg)}`,
              value: Number(service.total),
              display: formatMoney(service.total),
            }))}
          />
        </div>
      </Card>

      <Card padded={false}>
        <div className="card-head">
          <div>
            <h3>Movimientos del período</h3>
            <span className="sub">gastos, otros ingresos y descuentos a médicos</span>
          </div>
          <span className="muted">
            {`Por cobrar (cartera): ${formatMoney(data.receivable_total)}`}
          </span>
        </div>

        {entries.isError || entries.isPaused ? (
          // Never show "todavía no registras nada" when the truth is that the
          // list failed to load.
          <QueryError
            error={entries.error}
            area="los movimientos"
            onRetry={() => void entries.refetch()}
            retrying={entries.isFetching}
            paused={entries.isPaused}
          />
        ) : (
          <>
          {/* The way in, always on screen: the button up in the toolbar is easy
              to miss, and a report nobody knows they can feed stays empty. */}
          {canManage && (
            <button type="button" className="add-tile" onClick={() => setCreating(true)}>
              <span className="at-ic"><Icon name="plus" size={16} /></span>
              <span className="at-body">
                <b>Registrar un gasto, ingreso o descuento</b>
                <span className="muted">
                  Arriendo, insumos, sueldos, alquiler de consultorio o un descuento a un médico.
                </span>
              </span>
            </button>
          )}

          {rows.length === 0 ? (
            <div className="card-pad">
              <p className="rbar-empty">
                Todavía no registras gastos ni otros ingresos en este período. Sin ellos, la utilidad
                neta solo descuenta las comisiones de los médicos.
              </p>
            </div>
          ) : (
          <table className="tbl tbl-cards">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Categoría</th>
                <th>Descripción</th>
                <th className="num">Monto</th>
                {canManage && <th />}
              </tr>
            </thead>
            <tbody>
              {rows.map((entry) => (
                <tr key={entry.id}>
                  <td data-label="Fecha">{formatDate(entry.entry_date)}</td>
                  <td data-label="Tipo">
                    <span className={`tag ${entry.type === 'income' ? 'ok' : 'warn'}`}>
                      {TYPE_LABELS[entry.type]}
                    </span>
                  </td>
                  <td data-label="Categoría">{CATEGORY_LABELS[entry.category] ?? entry.category}</td>
                  <td data-label="Descripción">
                    {entry.description}
                    {entry.doctor_name && <span className="muted"> · {entry.doctor_name}</span>}
                    {entry.receipt_number && <span className="muted"> · {entry.receipt_number}</span>}
                  </td>
                  <td className="num mono" data-label="Monto">
                    {entry.type === 'income' ? '' : '− '}
                    {formatMoney(entry.amount)}
                  </td>
                  {canManage && (
                    <td data-label="">
                      <button
                        className="icon-btn"
                        type="button"
                        aria-label="Editar movimiento"
                        onClick={() => setEditing(entry)}
                      >
                        <Icon name="edit" size={15} />
                      </button>
                      <button
                        className="icon-btn danger"
                        type="button"
                        aria-label="Eliminar movimiento"
                        disabled={remove.isPending}
                        onClick={() => {
                          if (window.confirm(`¿Eliminar "${entry.description}" por ${formatMoney(entry.amount)}?`)) {
                            remove.mutate(entry.id);
                          }
                        }}
                      >
                        <Icon name="trash" size={15} />
                      </button>
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
          )}
          </>
        )}
      </Card>

      {(creating || editing) && (
        <FinanceEntryModal
          entry={editing}
          defaultDate={period.to}
          onClose={() => {
            setCreating(false);
            setEditing(null);
          }}
          onSaved={refresh}
        />
      )}
    </div>
  );
}
