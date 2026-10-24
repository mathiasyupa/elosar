import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { fetchClinicalReport, downloadRdacaa } from '@/api/reports';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { ExcelButton } from '@/components/ui/ExcelButton';
import { Icon } from '@/components/Icon';
import { stampedFilename } from '@/lib/excel';
import { clinicalSheets } from './exports';
import { CONSULTATION_TYPE_LABELS } from '@/features/patients/workspace/StructuredExamFields';
import { t } from '@/i18n/es';
import { GENDER_LABELS } from '@/i18n/labels';
import { QueryError } from '@/components/ui/QueryError';
import { ReportBars } from './ReportBars';
import type { Period } from './PeriodBar';

type View = 'morbilidad' | 'produccion';

const VIEWS: { key: View; label: string; icon: string }[] = [
  { key: 'morbilidad', label: 'Morbilidad', icon: 'steth' },
  { key: 'produccion', label: 'Producción', icon: 'chart' },
];

/**
 * Health surveillance over the period the whole screen is set to, in two
 * views instead of one long page: Morbilidad (what is seen and in whom) and
 * Producción (how many attentions, of what kind, by whom, with what
 * medicines). The MSP RDACAA export and the Excel stay on both.
 *
 * The call lists (probable deliveries, vaccines) moved to Pacientes →
 * Seguimiento pendiente: they do not depend on the period.
 */
export function ClinicalReportsPanel({ period }: { period: Period }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const [searchParams, setSearchParams] = useSearchParams();
  const view: View = searchParams.get('vista') === 'produccion' ? 'produccion' : 'morbilidad';
  const { from, to } = period;
  const [downloading, setDownloading] = useState(false);
  const [downloadError, setDownloadError] = useState<string | null>(null);

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['clinical-report', activeWorkspaceId, from, to],
    queryFn: () => fetchClinicalReport({ from, to }),
  });

  const selectView = (next: View) => {
    const params = new URLSearchParams(searchParams);
    if (next === 'morbilidad') params.delete('vista');
    else params.set('vista', next);
    setSearchParams(params, { replace: true });
  };

  const handleRdacaa = async () => {
    setDownloading(true);
    setDownloadError(null);
    try {
      await downloadRdacaa({ from, to });
    } catch {
      setDownloadError('No se pudo generar el RDACAA. Intenta de nuevo.');
    } finally {
      setDownloading(false);
    }
  };

  const dem = data?.demographics;

  return (
    <div className="stack">
      <div className="panel-toolbar">
        <div className="pill-row" role="tablist" aria-label="Vista de vigilancia">
          {VIEWS.map((v) => (
            <button
              key={v.key}
              type="button"
              role="tab"
              aria-selected={view === v.key}
              className={`pill ${view === v.key ? 'active' : ''}`}
              onClick={() => selectView(v.key)}
            >
              <Icon name={v.icon} size={14} /> {v.label}
            </button>
          ))}
        </div>
        <div className="row wrap push-right">
          <ExcelButton
            filename={stampedFilename('vigilancia-salud')}
            label="Excel"
            disabled={!data}
            build={() => clinicalSheets(data!)}
          />
          <button className="btn primary" type="button" onClick={handleRdacaa} disabled={downloading}>
            <Icon name="folder" size={16} />
            {downloading ? 'Generando…' : 'Descargar RDACAA'}
          </button>
        </div>
      </div>

      {downloadError && <p className="alert red" role="alert">{downloadError}</p>}
      {isLoading && <Spinner label={t.common.loading} />}
      {(isError || isPaused) && (
        <QueryError error={error} area="el reporte clínico" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
      )}

      {data && view === 'morbilidad' && (
        <div className="stack">
          <div className="grid g-2">
            <Card padded={false}>
              <div className="card-head"><h3>Principales patologías (CIE-10)</h3></div>
              <div className="card-pad">
                <ReportBars
                  label="Principales patologías"
                  empty="Sin diagnósticos registrados en el período."
                  data={data.morbidity.map((m) => ({ label: `${m.code} · ${m.description}`, value: m.total }))}
                />
              </div>
            </Card>
            <Card padded={false}>
              <div className="card-head"><h3>Por sistema o aparato</h3></div>
              <div className="card-pad">
                <ReportBars tone="green" label="Por sistema" data={data.by_system.map((s) => ({ label: s.system, value: s.total }))} />
              </div>
            </Card>
          </div>

          <div className="grid g-3">
            <Card padded={false}>
              <div className="card-head"><h3>Por sexo</h3></div>
              <div className="card-pad">
                <ReportBars
                  tone="violet"
                  label="Por sexo"
                  data={(dem?.by_gender ?? []).map((g) => ({ label: GENDER_LABELS[g.gender] ?? g.gender, value: g.total }))}
                />
              </div>
            </Card>
            <Card padded={false}>
              <div className="card-head"><h3>Por grupo de edad</h3></div>
              <div className="card-pad">
                <ReportBars tone="blue" label="Por grupo de edad" data={(dem?.by_age_group ?? []).map((a) => ({ label: a.group, value: a.total }))} />
              </div>
            </Card>
            <Card padded={false}>
              <div className="card-head"><h3>Por ciudad</h3></div>
              <div className="card-pad">
                <ReportBars tone="green" label="Por ciudad" data={(dem?.by_city ?? []).map((c) => ({ label: c.city, value: c.total }))} />
              </div>
            </Card>
          </div>
        </div>
      )}

      {data && view === 'produccion' && (
        <div className="stack">
          <div className="grid g-2">
            <Card padded={false}>
              <div className="card-head">
                <h3>Atenciones por tipo</h3>
                <span className="sub">{data.attentions.total} en el período</span>
              </div>
              <div className="card-pad">
                <ReportBars
                  tone="blue"
                  label="Atenciones por tipo"
                  empty="Sin atenciones en el período."
                  data={data.attentions.by_type.map((r) => ({
                    label: CONSULTATION_TYPE_LABELS[r.type as keyof typeof CONSULTATION_TYPE_LABELS] ?? r.type,
                    value: r.total,
                  }))}
                />
              </div>
            </Card>
            <Card padded={false}>
              <div className="card-head"><h3>Atenciones por médico</h3></div>
              <div className="card-pad">
                <ReportBars
                  label="Atenciones por médico"
                  empty="Sin atenciones en el período."
                  data={data.attentions.by_doctor.map((d) => ({ label: d.name, value: d.total }))}
                />
              </div>
            </Card>
          </div>

          <div className="grid g-2">
            <Card padded={false}>
              <div className="card-head"><h3>Medicamentos más prescritos</h3></div>
              <div className="card-pad">
                <ReportBars
                  tone="amber"
                  label="Medicamentos más prescritos"
                  empty="Sin recetas en el período."
                  data={data.medications.map((m) => ({
                    label: m.controlled > 0 ? `${m.medication} (controlado)` : m.medication,
                    value: m.total,
                  }))}
                />
              </div>
            </Card>
            <Card padded={false}>
              <div className="card-head"><h3>Atenciones por mes</h3></div>
              <div className="card-pad">
                <ReportBars
                  tone="violet"
                  label="Atenciones por mes"
                  data={data.attentions.monthly.map((m) => ({ label: m.label, value: m.total }))}
                />
              </div>
            </Card>
          </div>
        </div>
      )}

      <p className="report-moved">
        <Icon name="cal" size={14} /> Los partos probables y las vacunas por aplicar están en{' '}
        <Link to="/pacientes?view=follow-ups">Pacientes → Seguimiento pendiente</Link>.
      </p>
    </div>
  );
}
