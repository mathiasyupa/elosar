import { useOpenPatient } from '@/lib/openPatient';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { checkInAppointment } from '@/api/clinic';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useUiStore } from '@/store/ui';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { avatarColor, initials } from '@/lib/theme';
import { formatTime } from '@/i18n/es';
import type { QueueRow } from '@/api/types';

const STATUS_LABELS: Record<string, string> = {
  scheduled: 'Agendada',
  checked_in: 'En sala',
  vitals_done: 'Signos tomados',
  with_doctor: 'Con el médico',
  completed: 'Completada',
  cancelled: 'Cancelada',
  no_show: 'No se presentó',
};

interface Props {
  rows: QueueRow[];
  /** Hide secondary columns/actions for the compact dashboard widget. */
  compact?: boolean;
}

/**
 * Day-queue table shared by the clinic dashboard and the /cola page. Actions are
 * role-aware: check-in (reception/nurse), triage (nurse), atender (doctor). The
 * API enforces each one regardless of what is shown.
 */
export function QueueTable({ rows, compact = false }: Props) {
  const { can } = useWorkspaceCapabilities();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const openPatient = useOpenPatient();
  const toast = useUiStore((s) => s.toast);

  const checkIn = useMutation({
    mutationFn: (id: number) => checkInAppointment(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['clinic-queue'] });
      // 'dashboard' es la clave real de la query del tablero; 'clinic-dashboard'
      // no correspondía a ninguna y no refrescaba nada.
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      toast?.('Paciente registrado en sala.', 'success');
    },
    onError: () => toast?.('No se pudo registrar la llegada.', 'error'),
  });

  return (
    <table className="tbl tbl-cards">
      <tbody>
        {rows.map((a) => (
          <tr key={a.id}>
            <td style={{ width: 64 }} data-label="Hora"><b className="mono">{formatTime(a.time)}</b></td>
            <td data-label="Paciente">
              <div className="who">
                <span className="avatar-sm" style={{ background: avatarColor(a.patient?.id ?? a.id) }}>
                  {initials(a.patient?.name ?? '—')}
                </span>
                <div style={{ minWidth: 0 }}>
                  {a.patient ? (
                    <PatientWorkspaceLink patientId={a.patient.id}>
                      <b>{a.patient.name}</b>
                    </PatientWorkspaceLink>
                  ) : (
                    <b>—</b>
                  )}
                  {!compact && (
                    <span className="muted" style={{ display: 'block', fontSize: 12 }}>
                      <span className="mono">CI {a.patient?.id_number ?? '—'}</span>
                    </span>
                  )}
                </div>
                {a.patient?.is_first_visit && <span className="ev-first" style={{ background: 'var(--accent)' }}>1ª</span>}
                {a.patient?.has_allergies && (
                  <span className="badge red plain" title="Alergias registradas">
                    <Icon name="shield" size={12} /> Alergia
                  </span>
                )}
              </div>
            </td>
            {!compact && <td className="muted" style={{ fontSize: 12.5 }} data-label="Médico">{a.doctor?.name ?? '—'}</td>}
            <td className="num" data-label="Estado"><StatusBadge status={a.status} label={STATUS_LABELS[a.status] ?? a.status} /></td>
            <td className="num" style={{ whiteSpace: 'nowrap' }} data-label="">
              {a.status === 'scheduled' && can('appointments.checkin') && (
                <button className="btn sm ghost" onClick={() => checkIn.mutate(a.id)} disabled={checkIn.isPending}>
                  Registrar llegada
                </button>
              )}
              {a.status === 'checked_in' && can('vitals.create') && (
                <button className="btn sm ghost" onClick={() => navigate(`/triaje/${a.id}`)}>
                  Signos
                </button>
              )}
              {(a.status === 'vitals_done' || a.status === 'with_doctor') && can('records.manage') && a.patient && (
                <button
                  className="btn sm primary"
                  onClick={() =>
                    openPatient(
                      a.patient!.id,
                      a.draft_record_id
                        ? `atencion?draft=${a.draft_record_id}`
                        : `atencion?appointment=${a.id}`,
                    )
                  }
                >
                  Atender
                </button>
              )}
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  );
}
