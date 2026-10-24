import { useQuery } from '@tanstack/react-query';
import { fetchClinicalReport } from '@/api/reports';
import { useAuthStore } from '@/store/auth';
import { useClinicalModules } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { formatDate } from '@/i18n/es';

/**
 * The two clinical call lists that used to sit at the bottom of Reportes →
 * Vigilancia: probable delivery dates and vaccine reminders. They do not
 * depend on the report period (vaccines from a month ago to two months
 * ahead, pregnancies in control), so they are work to do, not statistics,
 * and belong next to the other follow-ups.
 *
 * Only for members with reports.clinical, the same gate the endpoint has.
 */
export function ClinicalWorklists() {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const clinicalModules = useClinicalModules();

  const { data, isLoading, isError } = useQuery({
    queryKey: ['clinical-report', activeWorkspaceId, 'worklists'],
    queryFn: () => fetchClinicalReport(),
  });

  if (isLoading) return <Card><Spinner label="Cargando partos y vacunas…" /></Card>;
  if (isError || !data) return <p className="rbar-empty">No se pudieron cargar los partos probables ni las vacunas.</p>;

  const deliveries = data.probable_deliveries;
  const showDeliveries = clinicalModules.has('gyneco_obstetrics') && deliveries !== undefined;
  const vaccines = data.vaccine_reminders.items;

  return (
    <div className={showDeliveries ? 'grid g-2 worklists' : 'stack'}>
      {showDeliveries && (
        <Card padded={false}>
          <div className="card-head">
            <h3>Fechas probables de parto</h3>
            <span className="sub">{deliveries.count} en control</span>
          </div>
          {deliveries.items.length === 0 ? (
            <div className="card-pad"><p className="rbar-empty">Sin embarazos en control con fecha probable.</p></div>
          ) : (
            <div className="table-scroll">
              <table className="tbl">
                <thead>
                  <tr>
                    <th>Paciente</th>
                    <th className="num">Edad</th>
                    <th className="num">Semanas</th>
                    <th className="num">FPP</th>
                  </tr>
                </thead>
                <tbody>
                  {deliveries.items.map((d) => (
                    <tr key={d.patient_id}>
                      <td>
                        <PatientWorkspaceLink patientId={d.patient_id} section="gineco">{d.patient ?? '—'}</PatientWorkspaceLink>
                        {/* The FUM is still in a closed consultation: no pregnancy
                            is registered yet. It is not migrated by itself — the
                            doctor decides. */}
                        {d.origin === 'legacy_exam' && (
                          <span className="tag warn" title="La FUM está en una consulta, no hay embarazo registrado">
                            sin registrar
                          </span>
                        )}
                      </td>
                      <td className="num mono">{d.age ?? '—'}</td>
                      <td className="num mono">
                        {d.weeks ?? '—'}
                        {d.dating === 'ultrasound' && <span className="muted"> eco</span>}
                      </td>
                      <td className="num mono">{formatDate(d.edd)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <Card padded={false}>
        <div className="card-head">
          <h3>Vacunas por aplicar</h3>
          <span className="sub">vencidas y de los próximos dos meses</span>
        </div>
        {vaccines.length === 0 ? (
          <div className="card-pad"><p className="rbar-empty">Sin vacunas pendientes.</p></div>
        ) : (
          <div className="table-scroll">
            <table className="tbl">
              <thead><tr><th>Paciente</th><th>Vacuna</th><th className="num">Fecha</th></tr></thead>
              <tbody>
                {vaccines.map((v, i) => (
                  <tr key={`${v.patient_id}-${i}`}>
                    <td>
                      <PatientWorkspaceLink patientId={v.patient_id} section="vacunas">{v.patient ?? '—'}</PatientWorkspaceLink>
                    </td>
                    <td>{v.vaccine} · {v.dose}</td>
                    <td className="num mono">
                      {v.reminder_at ? formatDate(v.reminder_at) : '—'}
                      {v.overdue && <span className="tag warn">vencida</span>}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
