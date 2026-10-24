import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { fetchPendingFollowUps } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { avatarColor, initials } from '@/lib/theme';
import { followUpMessage } from '@/lib/whatsapp';
import { formatDate } from '@/i18n/es';

/** "hace 12 días" / "en 5 días" — the urgency at a glance. */
function overdueLabel(days: number): string {
  if (days === 0) return 'El control es hoy';
  if (days < 0) {
    const n = Math.abs(days);
    return n === 1 ? 'Atrasado 1 día' : `Atrasado ${n} días`;
  }
  return days === 1 ? 'El control es mañana' : `En ${days} días`;
}

/**
 * Controls indicated by the doctor that do not have a future active appointment.
 * Unlike the re-engage list (commercial), the reason to contact here is clinical.
 *
 * Reception can work this list without seeing the clinical note; the API
 * withholds it from members without records.view.
 */
export function PendingFollowUps() {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);

  const { data, isLoading, isError } = useQuery({
    queryKey: ['pending-follow-ups', activeWorkspaceId],
    queryFn: fetchPendingFollowUps,
  });

  if (isLoading) return <Card><Spinner label="Cargando controles…" /></Card>;
  if (isError || !data) {
    return <Card><p style={{ color: 'var(--red)', fontSize: 13 }}>No se pudieron cargar los controles.</p></Card>;
  }

  if (data.items.length === 0) {
    return (
      <Card>
        <EmptyState
          icon="check"
          message="No hay controles pendientes."
          hint="Cuando indiques un próximo control en una consulta y el paciente no agende, aparecerá aquí para llamarlo."
        />
      </Card>
    );
  }

  return (
    <div className="stack" style={{ gap: 14 }}>
      <p className="muted" style={{ fontSize: 13, margin: 0 }}>
        {data.overdue > 0
          ? `Contacta y agenda: ${data.overdue} paciente${data.overdue === 1 ? '' : 's'} con el control atrasado. Es un control indicado por el médico sin una cita futura activa; recepción debe contactar y agendar.`
          : 'Contacta y agenda controles indicados por el médico sin una cita futura activa; recepción debe contactar y agendar.'}
      </p>

      <Card padded={false}>
        <ul className="followup-list">
          {data.items.map((item) => (
            <li key={item.record_id} className={`fu-row ${item.overdue ? 'overdue' : ''}`}>
              <span className="avatar-sm" style={{ background: avatarColor(item.patient_id) }}>
                {initials(item.name ?? '?')}
              </span>

              <div className="fu-body">
                <div className="fu-top">
                  <PatientWorkspaceLink patientId={item.patient_id} className="fu-name">
                    {item.name ?? 'Paciente'}
                  </PatientWorkspaceLink>
                  <span className={`badge ${item.overdue ? 'red' : 'accent'} plain`}>{overdueLabel(item.days)}</span>
                </div>
                <span className="muted fu-meta">
                  Control indicado para el {formatDate(item.follow_up_date)}
                  {item.doctor ? ` · ${item.doctor}` : ''}
                </span>
                {item.notes && <span className="fu-note">{item.notes}</span>}
              </div>

              <div className="row wrap fu-actions">
                <WhatsAppButton
                  phone={item.phone}
                  message={followUpMessage(item.name ?? '', item.notes)}
                  label="Recordar control"
                />
                <Link className="btn sm" to={`/agenda?patient=${item.patient_id}`}>
                  <Icon name="cal" size={14} /> Agendar
                </Link>
              </div>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}
