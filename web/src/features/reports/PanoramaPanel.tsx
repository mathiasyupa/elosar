import { useQuery } from '@tanstack/react-query';
import { fetchClinicalReport, fetchDailyReport, fetchPnlReport } from '@/api/reports';
import { useAuthStore } from '@/store/auth';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { QueryError } from '@/components/ui/QueryError';
import { Icon } from '@/components/Icon';
import { formatMoney, t } from '@/i18n/es';
import { DailyBars } from './DailyFinancePanel';
import { ReportBars, SplitBar } from './ReportBars';
import { comparisonWindow, type Period } from './PeriodBar';
import type { ReportTab } from './ReportsPage';

type Tone = 'accent' | 'green' | 'blue' | 'amber';

/** Percentage change, or null when there is nothing to compare against. */
export function percentChange(current: number, previous: number): number | null {
  if (!Number.isFinite(current) || !Number.isFinite(previous) || previous <= 0) return null;
  return ((current - previous) / previous) * 100;
}

function deltaText(pct: number): string {
  // «+13301 %» reads as noise; past tripling, say how many times over.
  if (pct >= 200) return `×${String(Math.round((pct / 100 + 1) * 10) / 10).replace('.', ',')}`;
  const rounded = Math.round(pct);
  if (rounded === 0) return 'igual';
  return `${rounded > 0 ? '+' : '−'}${Math.abs(rounded)} %`;
}

/**
 * One headline figure with how it moved. Up is not always good — more money
 * owed is not good news — so `upIsGood` decides the colour, not the arrow.
 */
function PanoKpi({
  icon,
  label,
  value,
  tone,
  current,
  previous,
  previousLabel,
  previousDisplay,
  note,
  upIsGood = true,
}: {
  icon: string;
  label: string;
  value: string;
  tone: Tone;
  current?: number;
  previous?: number;
  previousLabel?: string;
  previousDisplay?: string;
  note?: string;
  upIsGood?: boolean;
}) {
  const pct = current !== undefined && previous !== undefined ? percentChange(current, previous) : null;
  const flat = pct !== null && Math.round(pct) === 0;
  const good = pct !== null && (flat || (pct > 0) === upIsGood);

  return (
    <Card padded={false} className={`pano-kpi tone-${tone}`}>
      <span className="pano-kpi-label">
        <span className="pano-kpi-ic"><Icon name={icon} size={15} /></span>
        {label}
      </span>
      <b className="pano-kpi-value mono">{value}</b>
      {pct !== null ? (
        <span className={`pano-kpi-delta ${flat ? 'flat' : pct > 0 ? 'up' : 'down'} ${good ? 'good' : 'bad'}`}>
          <Icon name="arrow" size={12} /> {deltaText(pct)}
          <span className="muted"> vs. {previousLabel}{previousDisplay ? ` (${previousDisplay})` : ''}</span>
        </span>
      ) : (
        <span className="pano-kpi-delta muted">{note ?? (previousLabel ? `sin datos de ${previousLabel}` : '')}</span>
      )}
    </Card>
  );
}

function DetailLink({ tab, onOpen, children }: { tab: ReportTab; onOpen: (tab: ReportTab) => void; children: string }) {
  return (
    <button type="button" className="btn ghost sm pano-more" onClick={() => onOpen(tab)}>
      {children} <Icon name="arrow" size={13} />
    </button>
  );
}

/**
 * «¿Cómo va el consultorio?» answered on one screen: four figures against
 * the same stretch of the previous period, the money day by day, what is
 * being seen most and who is coming. Every block links to the tab that has
 * the detail. Each block only asks for what the role may see: reception
 * without clinical reports never requests them.
 */
export function PanoramaPanel({ period, onOpen }: { period: Period; onOpen: (tab: ReportTab) => void }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const { can } = useWorkspaceCapabilities();
  const canClinical = can('reports.clinical');
  const canFinance = can('finance.view');
  const compare = comparisonWindow(period);
  const prev = compare.period;

  // Same keys as the detailed tabs: opening one after this is instant.
  const daily = useQuery({
    queryKey: ['reports', 'daily', activeWorkspaceId, period.from, period.to],
    queryFn: () => fetchDailyReport(period),
    placeholderData: (previous) => previous,
  });
  const dailyPrev = useQuery({
    queryKey: ['reports', 'daily', activeWorkspaceId, prev.from, prev.to],
    queryFn: () => fetchDailyReport(prev),
  });
  const clinical = useQuery({
    queryKey: ['clinical-report', activeWorkspaceId, period.from, period.to],
    queryFn: () => fetchClinicalReport(period),
    enabled: canClinical,
    placeholderData: (previous) => previous,
  });
  const clinicalPrev = useQuery({
    queryKey: ['clinical-report', activeWorkspaceId, prev.from, prev.to],
    queryFn: () => fetchClinicalReport(prev),
    enabled: canClinical,
  });
  const pnl = useQuery({
    queryKey: ['reports', 'pnl', activeWorkspaceId, period.from, period.to],
    queryFn: () => fetchPnlReport(period),
    enabled: canFinance,
  });

  if (daily.isPending) return <Spinner label={t.common.loading} />;
  if (daily.isError || !daily.data) {
    return (
      <QueryError
        error={daily.error}
        area="el panorama"
        onRetry={() => void daily.refetch()}
        retrying={daily.isFetching}
        paused={daily.isPaused}
      />
    );
  }

  const day = daily.data;
  const c = clinical.data;
  const cp = clinicalPrev.data;
  const billed = day.days.reduce((sum, d) => sum + Number(d.billed), 0);
  const topDiagnoses = (c?.morbidity ?? []).slice(0, 5);
  const isSingleDay = day.days.length === 1;

  return (
    <div className={`stack pano ${daily.isFetching || clinical.isFetching ? 'is-refetching' : ''}`}>
      <div className="pano-kpis">
        {canClinical && (
          <PanoKpi
            icon="steth"
            label="Atenciones"
            tone="accent"
            value={c ? String(c.attentions.total) : '…'}
            current={c?.attentions.total}
            previous={cp?.attentions.total}
            previousLabel={compare.label}
            previousDisplay={cp ? String(cp.attentions.total) : undefined}
          />
        )}
        {canClinical && (
          <PanoKpi
            icon="users"
            label="Pacientes nuevos"
            tone="blue"
            value={c ? String(c.demographics.new_patients) : '…'}
            current={c?.demographics.new_patients}
            previous={cp?.demographics.new_patients}
            previousLabel={compare.label}
            previousDisplay={cp ? String(cp.demographics.new_patients) : undefined}
          />
        )}
        <PanoKpi
          icon="money"
          label="Cobrado"
          tone="green"
          value={formatMoney(day.summary.collected)}
          current={Number(day.summary.collected)}
          previous={dailyPrev.data ? Number(dailyPrev.data.summary.collected) : undefined}
          previousLabel={compare.label}
          previousDisplay={dailyPrev.data ? formatMoney(dailyPrev.data.summary.collected) : undefined}
        />
        {canFinance ? (
          <PanoKpi
            icon="card"
            label="Por cobrar"
            tone="amber"
            value={pnl.data ? formatMoney(pnl.data.receivable_total) : '…'}
            note="facturas con saldo pendiente"
          />
        ) : (
          <PanoKpi
            icon="card"
            label="Facturado"
            tone="amber"
            value={formatMoney(billed.toFixed(2))}
            note="en el período"
          />
        )}
      </div>

      <div className={canClinical ? 'pano-main' : 'stack'}>
        <Card padded={false}>
          <div className="card-head">
            <div className="pano-head-text">
              <h3>Cobrado por día</h3>
              <span className="sub">
              {Number(day.summary.projection ?? 0) > 0
                ? `promedio ${formatMoney(day.summary.daily_avg)} · proyección de cierre ${formatMoney(day.summary.projection)}`
                  : `promedio ${formatMoney(day.summary.daily_avg)} por día`}
              </span>
            </div>
            <DetailLink tab="dia" onOpen={onOpen}>Día a día</DetailLink>
          </div>
          <div className="card-pad">
            {isSingleDay ? (
              <p className="rbar-empty">Elige una semana o un mes para ver el ritmo día por día.</p>
            ) : Number(day.summary.collected) <= 0 ? (
              <p className="rbar-empty">Sin cobros registrados en el período.</p>
            ) : (
              <DailyBars days={day.days} avg={day.summary.daily_avg} />
            )}
          </div>
        </Card>

        {canClinical && (
          <div className="stack">
            <Card padded={false}>
              <div className="card-head">
                <h3>Lo que más se atiende</h3>
                <DetailLink tab="vigilancia" onOpen={onOpen}>Morbilidad</DetailLink>
              </div>
              <div className="card-pad">
                {clinical.isPending ? (
                  <Spinner label={t.common.loading} />
                ) : (
                  <ReportBars
                    label="Diagnósticos más frecuentes"
                    empty="Sin diagnósticos registrados en el período."
                    data={topDiagnoses.map((m) => ({ label: `${m.code} · ${m.description}`, value: m.total }))}
                  />
                )}
              </div>
            </Card>
            {c && (
              <Card padded={false}>
                <div className="card-head">
                  <div className="pano-head-text">
                    <h3>Quién vino</h3>
                    <span className="sub">pacientes atendidos en el período</span>
                  </div>
                </div>
                <div className="card-pad">
                  <SplitBar
                    label="Pacientes nuevos y que vuelven"
                    first={{ label: 'Nuevos', value: c.demographics.new_patients }}
                    second={{ label: 'Vuelven', value: c.demographics.returning_patients }}
                  />
                </div>
              </Card>
            )}
          </div>
        )}
      </div>
    </div>
  );
}
