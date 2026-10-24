import { useOpenPatient } from '@/lib/openPatient';
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchAppointments, markReminded, sendReminderEmail } from '@/api/appointments';
import { useAuthStore } from '@/store/auth';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useUiStore } from '@/store/ui';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { QueryError } from '@/components/ui/QueryError';
import { WhatsAppButton } from '@/components/WhatsAppButton';
import { EmailButton } from '@/components/EmailButton';
import { Icon } from '@/components/Icon';
import { AppointmentModal } from '@/features/agenda/NewAppointmentModal';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { avatarColor, initials } from '@/lib/theme';
import { formatDate, formatTime } from '@/i18n/es';
import { APPOINTMENT_TYPE_LABELS, labelFor } from '@/i18n/labels';
import { apptReminderMessage } from '@/lib/whatsapp';
import { ecuadorDateInputValue } from '@/lib/ecuadorDate';
import { normalizeText } from '@/lib/string';
import type { Appointment } from '@/api/types';

type AppointmentFilter = 'all' | 'today' | 'upcoming' | 'completed' | 'cancelled';

const FILTERS: { id: AppointmentFilter; label: string }[] = [
  { id: 'all', label: 'Todas' },
  { id: 'today', label: 'Hoy' },
  { id: 'upcoming', label: 'Próximas' },
  { id: 'completed', label: 'Atendidos' },
  { id: 'cancelled', label: 'Canceladas' },
];

export function PatientAppointmentsTab() {
  const [filter, setFilter] = useState<AppointmentFilter>('all');
  const [query, setQuery] = useState('');
  const [editing, setEditing] = useState<Appointment | null>(null);
  const openPatient = useOpenPatient();
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const { workspace } = useWorkspaceCapabilities();

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['appointments', activeWorkspaceId, 'patient-tab'],
    queryFn: () => fetchAppointments({ date: 'all' }),
  });

  const emailReminder = useMutation({
    mutationFn: (id: number) => sendReminderEmail(id),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['appointments'] });
      toast('Recordatorio enviado por correo.', 'success');
    },
    onError: () => toast('No se pudo enviar el correo. ¿El paciente tiene email?', 'error'),
  });

  const markWa = useMutation({
    mutationFn: (id: number) => markReminded(id),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['appointments'] }),
  });

  const appointments = data ?? [];

  const normalizedQuery = normalizeText(query);

  const filteredAppointments = useMemo(() => {
    const todayStr = ecuadorDateInputValue();
    return appointments.filter((a) => {
      if (!a.appointment_date) {
        if (filter !== 'all') return false;
      } else {
        const apptDateStr = a.appointment_date.slice(0, 10);
        if (filter === 'today' && !(apptDateStr === todayStr && a.status !== 'cancelled')) return false;
        if (filter === 'upcoming' && !(apptDateStr >= todayStr && a.status === 'scheduled')) return false;
        if (filter === 'completed' && a.status !== 'completed') return false;
        if (filter === 'cancelled' && a.status !== 'cancelled') return false;
      }

      if (!normalizedQuery) return true;
      const haystack = normalizeText(`${a.patient?.name ?? ''} ${a.patient?.id_number ?? ''}`);
      return haystack.includes(normalizedQuery);
    }).sort((x, y) => {
      // A history reads newest first. «Próximas» is the exception: the next
      // appointment is the one that matters, so it goes on top.
      const dx = x.appointment_date ?? '';
      const dy = y.appointment_date ?? '';
      return filter === 'upcoming' ? dx.localeCompare(dy) : dy.localeCompare(dx);
    });
  }, [appointments, filter, normalizedQuery]);

  if (isLoading) return <Spinner label="Cargando citas de pacientes…" />;
  if (isError || isPaused) {
    return (
      <Card padded>
        <QueryError error={error} area="las citas de pacientes" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
      </Card>
    );
  }

  return (
    <div className="stack" style={{ gap: 16 }}>
      <div>
        <h2>Citas y atenciones</h2>
        <p className="muted">Una atención es una cita completada; no confirma por sí sola una consulta clínica.</p>
      </div>

      <div className="search" style={{ maxWidth: 360 }}>
        <Icon name="search" />
        <input
          placeholder="Buscar por nombre o cédula…"
          value={query}
          onChange={(event) => setQuery(event.target.value)}
          aria-label="Buscar citas y atenciones por nombre o cédula"
        />
      </div>

      <div className="row between wrap" style={{ gap: 12 }}>
        <div className="pill-row">
          {FILTERS.map((f) => (
            <button
              key={f.id}
              type="button"
              className={`pill ${filter === f.id ? 'active' : ''}`}
              onClick={() => setFilter(f.id)}
            >
              {f.label}
            </button>
          ))}
        </div>
        <span className="muted" style={{ fontSize: 13 }}>
          {filteredAppointments.length} atención(es) encontrada(s)
        </span>
      </div>

      {filteredAppointments.length === 0 ? (
        <Card>
          <EmptyState
            icon="cal"
            message={
              normalizedQuery
                ? 'No hay citas que coincidan con la búsqueda.'
                : 'No hay atenciones registradas en este filtro.'
            }
            hint={
              normalizedQuery
                ? 'Revisa el nombre o la cédula, o quita el texto para ver todas las citas de este filtro.'
                : 'Atendidos incluye las consultas cerradas, incluso las registradas sin cita previa.'
            }
          />
        </Card>
      ) : (
        <Card padded={false} style={{ overflowX: 'auto' }}>
          <table className="tbl">
            <thead>
              <tr>
                <th>Fecha y Hora</th>
                <th>Paciente</th>
                <th>Tipo de Cita</th>
                <th>Médico / Establecimiento</th>
                <th>Estado</th>
                <th style={{ textAlign: 'right' }}>Acciones</th>
              </tr>
            </thead>
            <tbody>
              {filteredAppointments.map((appt) => {
                const patientName = appt.patient?.name ?? 'Paciente sin nombre';
                const isScheduled = appt.status === 'scheduled';
                const message = apptReminderMessage({
                  name: patientName,
                  dateLabel: appt.appointment_date ? formatDate(appt.appointment_date) : '',
                  timeLabel: appt.appointment_date ? formatTime(appt.appointment_date) : '',
                  doctorName: appt.doctor_name ?? null,
                });

                return (
                  <tr
                    key={appt.id}
                    className="row-link"
                    onClick={(e) => {
                      if ((e.target as HTMLElement).closest('button, a, input, select')) return;
                      setEditing(appt);
                    }}
                  >
                    <td className="mono" style={{ whiteSpace: 'nowrap' }}>
                      {appt.appointment_date ? (
                        <>
                          <b>{formatDate(appt.appointment_date)}</b>{' '}
                          <span className="muted">{formatTime(appt.appointment_date)}</span>
                        </>
                      ) : (
                        '—'
                      )}
                    </td>
                    <td>
                      <div className="who">
                        <span
                          className="avatar-sm"
                          style={{ background: avatarColor(appt.patient?.id ?? appt.id) }}
                        >
                          {initials(patientName)}
                        </span>
                        <div>
                          {appt.patient ? (
                            <PatientWorkspaceLink patientId={appt.patient.id}>
                              <b>{patientName}</b>
                            </PatientWorkspaceLink>
                          ) : (
                            <b>{patientName}</b>
                          )}
                          {appt.patient?.id_number && (
                            <span className="muted pp-id" style={{ display: 'block', fontSize: 11 }}>
                              <span className="mono">CI {appt.patient.id_number}</span>
                            </span>
                          )}
                        </div>
                      </div>
                    </td>
                    <td>
                      <span className="badge plain" style={{ fontSize: 11.5 }}>
                        {/* Sin el mapa de etiquetas, aquí salía el valor crudo:
                            el médico leía literalmente "walk_in". */}
                        {appt.type ? labelFor(APPOINTMENT_TYPE_LABELS, appt.type) : 'Consulta'}
                      </span>
                    </td>
                    <td>
                      <span style={{ fontSize: 12.5 }}>
                        {appt.doctor_name ?? workspace?.name ?? '—'}
                      </span>
                    </td>
                    <td>
                      <StatusBadge status={appt.status} label={appt.status_label} />
                    </td>
                    <td onClick={(e) => e.stopPropagation()}>
                      <div className="row" style={{ gap: 6, justifyContent: 'flex-end' }}>
                        {appt.patient && (
                          <button
                            type="button"
                            className="btn sm"
                            title="Atender consulta"
                            onClick={() =>
                              openPatient(appt.patient!.id, `atencion?appointment=${appt.id}`)
                            }
                          >
                            <Icon name="steth" size={14} /> Atender →
                          </button>
                        )}
                        {isScheduled && appt.patient && (
                          <>
                            <WhatsAppButton
                              phone={appt.patient.phone}
                              message={message}
                              label="WhatsApp"
                              onSent={() => markWa.mutate(appt.id)}
                            />
                            <EmailButton
                              email={appt.patient.email}
                              pending={
                                emailReminder.isPending && emailReminder.variables === appt.id
                              }
                              onSend={() => emailReminder.mutate(appt.id)}
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
        </Card>
      )}

      {editing && (
        <AppointmentModal
          appointment={editing}
          onClose={() => setEditing(null)}
          onCreated={() => {
            setEditing(null);
            qc.invalidateQueries({ queryKey: ['appointments'] });
          }}
        />
      )}
    </div>
  );
}
