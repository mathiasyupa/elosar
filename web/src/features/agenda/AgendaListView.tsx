import { useOpenPatient } from '@/lib/openPatient';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { markReminded, sendReminderEmail } from '@/api/appointments';
import { useUiStore } from '@/store/ui';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { EmptyState } from '@/components/ui/EmptyState';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { EmailButton } from '@/components/EmailButton';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { avatarColor, initials } from '@/lib/theme';
import { formatDate, formatTime } from '@/i18n/es';
import { apptReminderMessage } from '@/lib/whatsapp';
import type { Appointment } from '@/api/types';

interface Props {
  appointments: Appointment[];
  workspaceName: string;
  onRowClick: (a: Appointment) => void;
}

/**
 * Row-per-appointment list (Fecha/Hora · Cita · Paciente · Establecimiento ·
 * Servicio · Status · acciones). Replaces the standalone Recordatorios screen:
 * the WhatsApp/Email reminder actions live inline per appointment.
 */
export function AgendaListView({ appointments, workspaceName, onRowClick }: Props) {
  const toast = useUiStore((s) => s.toast);
  const openPatient = useOpenPatient();
  const qc = useQueryClient();

  const emailReminder = useMutation({
    mutationFn: (id: number) => sendReminderEmail(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['appointments'] });
      toast('Recordatorio enviado por correo.', 'success');
    },
    onError: () => toast('No se pudo enviar el correo. ¿El paciente tiene email?', 'error'),
  });
  const whatsapp = useMutation({ mutationFn: (id: number) => markReminded(id), onSuccess: () => qc.invalidateQueries({ queryKey: ['appointments'] }) });

  if (appointments.length === 0) {
    return (
      <div className="card">
        <EmptyState icon="cal" message="No hay citas para esta fecha." hint="Cambia de día o agenda una nueva cita." />
      </div>
    );
  }

  return (
    <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
      <table className="tbl tbl-cards">
        <thead>
          <tr>
            <th>Fecha/Hora</th>
            <th>Cita</th>
            <th>Paciente</th>
            <th>Establecimiento</th>
            <th>Servicio</th>
            <th>Status</th>
            <th>Acciones</th>
          </tr>
        </thead>
        <tbody>
          {appointments.map((a) => {
            const name = a.patient?.name ?? 'Paciente';
            const message = apptReminderMessage({
              name,
              dateLabel: a.appointment_date ? formatDate(a.appointment_date) : '',
              timeLabel: a.appointment_date ? formatTime(a.appointment_date) : '',
              doctorName: a.doctor_name ?? null,
            });
            const scheduled = a.status === 'scheduled';
            return (
              <tr
                key={a.id}
                className="row-link"
                onClick={(e) => {
                  if ((e.target as HTMLElement).closest('button, a, input, select')) return;
                  onRowClick(a);
                }}
              >
                <td className="mono" style={{ whiteSpace: 'nowrap' }} data-label="Fecha/Hora">
                  {a.appointment_date ? `${formatDate(a.appointment_date)} ${formatTime(a.appointment_date)}` : '—'}
                </td>
                <td className="mono" data-label="Cita">{a.id}</td>
                <td data-label="Paciente">
                  <div className="who">
                    <span className="avatar-sm" style={{ background: avatarColor(a.patient?.id ?? a.id) }}>{initials(name)}</span>
                    {a.patient ? (
                      <PatientWorkspaceLink patientId={a.patient.id}>
                        <b>{name}</b>
                      </PatientWorkspaceLink>
                    ) : (
                      <b>{name}</b>
                    )}
                  </div>
                </td>
                <td data-label="Establecimiento">{workspaceName}</td>
                <td data-label="Servicio">{a.service_name ?? <span className="muted">—</span>}</td>
                <td data-label="Status"><StatusBadge status={a.status} label={a.status_label} /></td>
                <td data-label="">
                  <div className="row" style={{ gap: 6 }}>
                    {a.patient && (
                      <button
                        className="btn sm"
                        title="Atender"
                        onClick={() => openPatient(a.patient!.id, `atencion?appointment=${a.id}`)}
                      >
                        <Icon name="steth" size={14} /> Atender
                      </button>
                    )}
                    {scheduled && (
                      <>
                        <WhatsAppButton phone={a.patient?.phone} message={message} label="WhatsApp" onSent={() => whatsapp.mutate(a.id)} />
                        <EmailButton
                          email={a.patient?.email}
                          pending={emailReminder.isPending && emailReminder.variables === a.id}
                          onSend={() => emailReminder.mutate(a.id)}
                          label="Email"
                        />
                      </>
                    )}
                  </div>
                </td>
              </tr>
            );
          })}
        </tbody>
      </table>
    </div>
  );
}
