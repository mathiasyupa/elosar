import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchSettlementReport } from '@/api/reports';
import type { SettlementDetailRow, SettlementReport } from '@/api/types';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { KpiMini } from '@/components/ui/Kpi';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { QueryError } from '@/components/ui/QueryError';
import { ExcelButton } from '@/components/ui/ExcelButton';
import { Icon } from '@/components/Icon';
import { stampedFilename } from '@/lib/excel';
import { formatMoney, formatDate, t } from '@/i18n/es';
import { useChartWidth } from './useChartWidth';
import { settlementSheets } from './exports';
import type { Period } from './PeriodBar';

const ROWS_PER_PAGE = 12;

/** Palette for the per-doctor donut — the design tokens, in a stable order. */
const DOCTOR_TONES = ['accent', 'blue', 'amber', 'violet', 'green', 'red'];

function compactMoney(n: number): string {
  if (n >= 10_000) return `$${Math.round(n / 1000)}k`;
  if (n >= 1000) return `$${(n / 1000).toFixed(1).replace(/\.0$/, '')}k`;
  return `$${Math.round(n)}`;
}

function niceCeil(n: number): number {
  if (n <= 0) return 100;
  const pow = 10 ** Math.floor(Math.log10(n));
  const unit = n / pow;
  const nice = unit <= 1 ? 1 : unit <= 2 ? 2 : unit <= 5 ? 5 : 10;
  return nice * pow;
}

/**
 * Three bars per doctor: what came in, what went to them and what stayed. The
 * question the clinic actually asks of this screen is comparative — who brings
 * money in and how much of it survives the commission.
 */
function DoctorBars({ rows }: { rows: SettlementReport['by_doctor'] }) {
  const [wrapRef, W] = useChartWidth();
  const data = rows.slice(0, 8);

  const max = niceCeil(Math.max(0, ...data.map((r) => Number(r.total))) || 1);
  const H = 240;
  const padL = 52;
  const padT = 12;
  const padB = 34;
  const plotW = W - padL - 12;
  const plotH = H - padT - padB;
  const slot = plotW / Math.max(1, data.length);
  const y = (v: number) => padT + plotH - (v / max) * plotH;

  return (
    <div className="chart-scroll" ref={wrapRef}>
      <svg viewBox={`0 0 ${W} ${H}`} width="100%" height={H} role="img" aria-label="Reparto por médico">
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

        {data.map((r, i) => {
          const bw = Math.min(22, slot * 0.2);
          const gap = 3;
          const bx = padL + i * slot + (slot - (bw * 3 + gap * 2)) / 2;
          const collected = Number(r.total);
          const toDoctor = Number(r.doctor_net ?? 0);
          const toClinic = Number(r.clinic_amount ?? r.total);
          const bar = (value: number, index: number, fill: string) => (
            <rect
              x={bx + index * (bw + gap)}
              y={y(value)}
              width={bw}
              height={Math.max(0, plotH + padT - y(value))}
              rx={3.5}
              fill={fill}
            />
          );

          return (
            <g key={r.doctor_id ?? 'none'}>
              <title>
                {`${r.name} — Cobrado: ${formatMoney(r.total)} · Al médico: ${formatMoney(
                  r.doctor_net ?? '0',
                )} · A la clínica: ${formatMoney(r.clinic_amount ?? r.total)}`}
              </title>
              {bar(collected, 0, 'var(--accent-soft)')}
              {bar(toDoctor, 1, 'var(--amber)')}
              {bar(toClinic, 2, 'var(--accent)')}
              <text
                x={padL + i * slot + slot / 2}
                y={H - 12}
                textAnchor="middle"
                fontSize="10.5"
                fill="var(--text-3)"
              >
                {r.name.length > 16 ? `${r.name.slice(0, 15)}…` : r.name}
              </text>
            </g>
          );
        })}
      </svg>
    </div>
  );
}

/** Donut of what the clinic keeps, per doctor. */
function ClinicShareDonut({ rows }: { rows: SettlementReport['by_doctor'] }) {
  const segments = rows
    .map((r, i) => ({
      label: r.name,
      value: Number(r.clinic_amount ?? r.total),
      tone: DOCTOR_TONES[i % DOCTOR_TONES.length],
    }))
    .filter((s) => s.value > 0);

  const total = segments.reduce((sum, s) => sum + s.value, 0);
  const size = 150;
  const r = size / 2;
  const stroke = 22;
  const radius = r - stroke / 2;
  const circ = 2 * Math.PI * radius;
  let offset = 0;

  return (
    <div className="donut-row">
      <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} role="img" aria-label="Neto de la clínica por médico">
        <g transform={`rotate(-90 ${r} ${r})`}>
          {total === 0 ? (
            <circle cx={r} cy={r} r={radius} fill="none" stroke="var(--border)" strokeWidth={stroke} />
          ) : (
            segments.map((s) => {
              const len = (s.value / total) * circ;
              const el = (
                <circle
                  key={s.label}
                  cx={r}
                  cy={r}
                  r={radius}
                  fill="none"
                  stroke={`var(--${s.tone})`}
                  strokeWidth={stroke}
                  strokeDasharray={`${len} ${circ - len}`}
                  strokeDashoffset={-offset}
                />
              );
              offset += len;
              return el;
            })
          )}
        </g>
        <text x={r} y={r - 2} textAnchor="middle" fontSize="15" fontWeight="700" fill="var(--text)">
          {compactMoney(total)}
        </text>
        <text x={r} y={r + 14} textAnchor="middle" fontSize="10" fill="var(--text-3)">
          neto clínica
        </text>
      </svg>

      <div className="legend-list">
        {segments.map((s) => (
          <div key={s.label} className="legend-item">
            <i className={`sw sw-${s.tone}`} />
            <span className="ll-label">{s.label}</span>
            <span className="muted">{total > 0 ? `${Math.round((s.value / total) * 100)}%` : '—'}</span>
            <b className="mono">{formatMoney(String(s.value))}</b>
          </div>
        ))}
      </div>
    </div>
  );
}

/** Attention-by-attention detail: the table a clinic audits its month with. */
function DetailTable({
  rows,
  scope,
}: {
  rows: SettlementDetailRow[];
  scope: 'own' | 'clinic';
}) {
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return rows;
    return rows.filter(
      (r) =>
        r.patient_name.toLowerCase().includes(q) ||
        r.service.toLowerCase().includes(q) ||
        r.doctor_name.toLowerCase().includes(q),
    );
  }, [rows, query]);

  const pages = Math.max(1, Math.ceil(filtered.length / ROWS_PER_PAGE));
  const current = Math.min(page, pages);
  const slice = filtered.slice((current - 1) * ROWS_PER_PAGE, current * ROWS_PER_PAGE);

  // With no commission configured nothing goes to the doctor, so the whole
  // collected amount stays with the clinic — showing "—" there would make the
  // column disagree with the totals above.
  const clinicShare = (row: SettlementDetailRow) => row.clinic_amount ?? row.collected;

  const sum = (pick: (row: SettlementDetailRow) => string) =>
    filtered.reduce((total, row) => total + Number(pick(row) ?? 0), 0);

  return (
    <Card padded={false}>
      <div className="card-head">
        <div>
          <h3>Detalle por atención</h3>
          <span className="sub">cada línea cobrada, con su reparto</span>
        </div>
        <div className="row wrap">
          <input
            className="field"
            type="search"
            placeholder="Buscar paciente, atención o médico…"
            value={query}
            onChange={(e) => {
              setQuery(e.target.value);
              setPage(1);
            }}
          />
        </div>
      </div>

      {filtered.length === 0 ? (
        <p className="rbar-empty">
          No hay atenciones cobradas en este período. El reporte se arma con los pagos registrados.
        </p>
      ) : (
        <>
          <table className="tbl tbl-cards settlement-tbl">
            <thead>
              <tr>
                <th>Fecha</th>
                {scope === 'clinic' && <th>Médico</th>}
                <th>Paciente</th>
                <th>Atención</th>
                <th className="num">Costo</th>
                <th className="num">Cobrado</th>
                <th className="num">Pago médico</th>
                <th className="num">Neto clínica</th>
              </tr>
            </thead>
            <tbody>
              {slice.map((r, i) => (
                <tr key={`${r.invoice_id}-${r.service}-${i}`}>
                  <td data-label="Fecha">{r.date ? formatDate(r.date) : '—'}</td>
                  {scope === 'clinic' && <td data-label="Médico">{r.doctor_name}</td>}
                  <td data-label="Paciente">
                    {r.patient_name}
                    {r.patient_id_number && <span className="muted"> · {r.patient_id_number}</span>}
                  </td>
                  <td data-label="Atención">
                    {r.service}
                    {r.quantity > 1 && <span className="muted"> ×{r.quantity}</span>}
                    {r.is_partial && (
                      <span className="tag warn" title="La factura está cobrada solo en parte">
                        parcial
                      </span>
                    )}
                  </td>
                  <td className="num mono" data-label="Costo">{formatMoney(r.price)}</td>
                  <td className="num mono" data-label="Cobrado">{formatMoney(r.collected)}</td>
                  <td className="num mono" data-label="Pago médico">
                    {r.doctor_amount !== null ? formatMoney(r.doctor_amount) : '—'}
                  </td>
                  <td className="num mono strong" data-label="Neto clínica">
                    {formatMoney(clinicShare(r))}
                  </td>
                </tr>
              ))}
              {/* The totals live in the body so the mobile card layout keeps them. */}
              <tr className="total-row">
                <td data-label="Total">
                  {`${filtered.length} ${filtered.length === 1 ? 'atención' : 'atenciones'}`}
                </td>
                {scope === 'clinic' && <td />}
                <td />
                <td />
                <td />
                <td className="num mono" data-label="Cobrado">
                  {formatMoney(String(sum((r) => r.collected)))}
                </td>
                <td className="num mono" data-label="Pago médico">
                  {formatMoney(String(sum((r) => r.doctor_amount ?? '0')))}
                </td>
                <td className="num mono strong" data-label="Neto clínica">
                  {formatMoney(String(sum(clinicShare)))}
                </td>
              </tr>
            </tbody>
          </table>

          {pages > 1 && (
            <div className="card-pad row between">
              <span className="muted">{`Página ${current} de ${pages}`}</span>
              <div className="pill-row">
                <button className="pill" type="button" disabled={current === 1} onClick={() => setPage(current - 1)}>
                  Anterior
                </button>
                <button className="pill" type="button" disabled={current === pages} onClick={() => setPage(current + 1)}>
                  Siguiente
                </button>
              </div>
            </div>
          )}
        </>
      )}
    </Card>
  );
}

/**
 * "Liquidación por médico": how much of what was collected in the period belongs
 * to each specialist and how much stays with the clinic — first as totals, then
 * attention by attention.
 */
export function SettlementPanel({ period, label }: { period: Period; label: string }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const [doctorId, setDoctorId] = useState<number | null>(null);

  const { data, isPending, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['reports', 'settlement', activeWorkspaceId, period.from, period.to, doctorId],
    queryFn: () => fetchSettlementReport(period, doctorId),
    placeholderData: (previous) => previous,
  });

  if (isPending) return <Spinner label={t.common.loading} />;
  if (isError || isPaused || !data)
    return (
      <QueryError
        error={error}
        area="la liquidación"
        onRetry={() => void refetch()}
        retrying={isFetching}
        paused={isPaused}
      />
    );

  const { totals, by_doctor: byDoctor, scope } = data;
  const hasMoney = Number(totals.collected) > 0;
  // The doctor filter only makes sense when there is a clinic to filter.
  const doctorOptions = byDoctor.filter((r) => r.doctor_id !== null);

  return (
    <div className={`stack ${isFetching ? 'is-refetching' : ''}`}>
      <div className="panel-toolbar">
        <span className="muted">
          {scope === 'own'
            ? 'Tu liquidación del período, atención por atención.'
            : 'Reparto clínica–especialista de todo lo cobrado en el período. El % se configura en Equipo.'}
        </span>
        <div className="row wrap push-right">
          {scope === 'clinic' && doctorOptions.length > 1 && (
            <select
              className="field"
              aria-label="Filtrar por médico"
              value={doctorId ?? ''}
              onChange={(e) => setDoctorId(e.target.value ? Number(e.target.value) : null)}
            >
              <option value="">Todos los médicos</option>
              {doctorOptions.map((r) => (
                <option key={r.doctor_id} value={r.doctor_id as number}>
                  {r.name}
                </option>
              ))}
            </select>
          )}
          <ExcelButton
            filename={stampedFilename('liquidacion-medicos')}
            label="Descargar Excel"
            build={() => settlementSheets(data)}
          />
        </div>
      </div>

      {!hasMoney ? (
        <Card>
          <EmptyState
            icon="money"
            message={`Sin cobros registrados en ${label}.`}
            hint="La liquidación se arma con los pagos del período: cuando registres un cobro aparecerá aquí."
          />
        </Card>
      ) : (
        <>
          {/* Decir por qué falta el reparto, en vez de una fila de guiones que
              parece un dato que no cargó. */}
          {!data.shows_compensation && (
            <p className="muted settlement-scope-note">
              <Icon name="shield" size={14} />
              Ves lo cobrado por médico, que es lo que necesitas para cuadrar caja. El reparto
              con cada especialista es información de compensación y la ve quien lleva los
              libros de la clínica.
            </p>
          )}

          <div className="grid g-4 report-kpi-grid">
            <KpiMini icon="money" label="Cobrado" value={formatMoney(totals.collected)} tone="blue" sub={label} />
            <KpiMini
              icon="user"
              label="A especialistas"
              value={formatMoney(totals.doctor_net)}
              tone="amber"
              sub={
                Number(totals.adjustments ?? 0) > 0
                  ? `${formatMoney(totals.doctor_amount)} − ${formatMoney(totals.adjustments)} en descuentos`
                  : 'comisiones del período'
              }
            />
            <KpiMini
              icon="building"
              label="Queda en la clínica"
              value={formatMoney(totals.clinic_amount)}
              tone="green"
              sub={
                totals.clinic_amount !== null && Number(totals.collected) > 0
                  ? `${Math.round((Number(totals.clinic_amount) / Number(totals.collected)) * 100)}% de lo cobrado`
                  : undefined
              }
            />
            <KpiMini
              icon="users"
              label="Atenciones"
              value={String(totals.attentions)}
              tone="violet"
              sub={`${totals.patients} ${totals.patients === 1 ? 'paciente' : 'pacientes'}`}
            />
          </div>

          {scope === 'clinic' && (
            <div className="grid g-2 report-analysis-grid">
              <Card padded={false}>
                <div className="card-head">
                  <div>
                    <h3>Cobrado, al médico y a la clínica</h3>
                    <span className="sub">{label}</span>
                  </div>
                  <div className="chart-legend">
                    <span><i className="sw sw-accent-soft" /> Cobrado</span>
                    <span><i className="sw sw-amber" /> Al médico</span>
                    <span><i className="sw sw-accent" /> A la clínica</span>
                  </div>
                </div>
                <div className="card-pad">
                  <DoctorBars rows={byDoctor} />
                </div>
              </Card>

              <Card padded={false}>
                <div className="card-head">
                  <h3>De dónde viene el neto</h3>
                  <span className="sub">lo que queda, por médico</span>
                </div>
                <div className="card-pad">
                  <ClinicShareDonut rows={byDoctor} />
                </div>
              </Card>
            </div>
          )}

          <Card padded={false}>
            <div className="card-head">
              <h3>{scope === 'own' ? 'Tu liquidación' : 'Resumen por médico'}</h3>
              <span className="sub">{label}</span>
            </div>
            <table className="tbl tbl-cards">
              <thead>
                <tr>
                  <th>Médico</th>
                  <th className="num">Atenciones</th>
                  <th className="num">Cobrado</th>
                  <th className="num">%</th>
                  <th className="num">Comisión</th>
                  <th className="num">Descuentos</th>
                  <th className="num">Al médico</th>
                  <th className="num">A la clínica</th>
                </tr>
              </thead>
              <tbody>
                {byDoctor.map((d) => (
                  <tr key={d.doctor_id ?? 'none'}>
                    <td data-label="Médico">
                      <Icon name="user" size={14} className="row-ic" />
                      {d.name}
                      {d.doctor_amount === null && (
                        <span className="tag" title="Este médico no tiene comisión configurada">
                          sin comisión
                        </span>
                      )}
                    </td>
                    <td className="num mono" data-label="Atenciones">{d.count}</td>
                    <td className="num mono" data-label="Cobrado">{formatMoney(d.total)}</td>
                    <td className="num mono" data-label="%">
                      {d.commission_pct !== null ? `${Number(d.commission_pct)}%` : '—'}
                    </td>
                    <td className="num mono" data-label="Comisión">
                      {d.doctor_amount !== null ? formatMoney(d.doctor_amount) : '—'}
                    </td>
                    <td className="num mono" data-label="Descuentos">
                      {Number(d.adjustments) > 0 ? `− ${formatMoney(d.adjustments)}` : '—'}
                    </td>
                    <td className="num mono" data-label="Al médico">
                      {d.doctor_net !== null ? formatMoney(d.doctor_net) : '—'}
                    </td>
                    <td className="num mono strong" data-label="A la clínica">
                      {/* No commission configured = nothing leaves, it all stays. */}
                      {formatMoney(d.clinic_amount ?? d.total)}
                    </td>
                  </tr>
                ))}
                <tr className="total-row">
                  <td data-label="Total">Total</td>
                  <td />
                  <td className="num mono" data-label="Cobrado">{formatMoney(totals.collected)}</td>
                  <td />
                  <td className="num mono" data-label="Comisión">{formatMoney(totals.doctor_amount)}</td>
                  <td className="num mono" data-label="Descuentos">
                    {Number(totals.adjustments) > 0 ? `− ${formatMoney(totals.adjustments)}` : '—'}
                  </td>
                  <td className="num mono" data-label="Al médico">{formatMoney(totals.doctor_net)}</td>
                  <td className="num mono strong" data-label="A la clínica">
                    {formatMoney(totals.clinic_amount)}
                  </td>
                </tr>
              </tbody>
            </table>
          </Card>

          <DetailTable rows={data.detail} scope={scope} />
        </>
      )}
    </div>
  );
}
