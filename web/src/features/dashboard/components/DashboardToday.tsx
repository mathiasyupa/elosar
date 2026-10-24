import { Link, useInRouterContext } from 'react-router-dom';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { StatusBadge } from '@/components/ui/StatusBadge';
import { formatTime } from '@/i18n/es';
import type { DashboardAppointmentView } from '../dashboardView';

interface DashboardTodayProps {
  nextAppointment: DashboardAppointmentView | null;
  appointments: DashboardAppointmentView[];
  canConsult: boolean;
  canOpenPatient: boolean;
  onOpenAppointment: (patientId: number, appointmentId: number) => void;
}

function AgendaLink({ className }: { className: string }) {
  const isInRouter = useInRouterContext();
  if (isInRouter) {
    return <Link className={className} to="/agenda">Ver agenda</Link>;
  }
  return <a className={className} href="/agenda">Ver agenda</a>;
}

function AppointmentAction({
  appointment,
  canConsult,
  canOpenPatient,
  onOpenAppointment,
}: {
  appointment: DashboardAppointmentView;
  canConsult: boolean;
  canOpenPatient: boolean;
  onOpenAppointment: DashboardTodayProps['onOpenAppointment'];
}) {
  const patient = appointment.patient;
  if (!patient || (!canConsult && !canOpenPatient)) return null;

  if (canConsult) {
    return (
      <Button
        size="sm"
        variant="primary"
        onClick={() => onOpenAppointment(patient.id, appointment.id)}
      >
        Atender
        <Icon name="arrow" size={14} />
      </Button>
    );
  }

  return (
    <PatientWorkspaceLink patientId={patient.id} className="btn sm">
      Abrir expediente
      <Icon name="arrow" size={14} />
    </PatientWorkspaceLink>
  );
}

function appointmentTimestamp(value: string | null): number | null {
  if (!value) return null;
  const timestamp = Date.parse(value);
  return Number.isNaN(timestamp) ? null : timestamp;
}

function compareAppointmentDates(
  left: DashboardAppointmentView,
  right: DashboardAppointmentView,
): number {
  const leftTimestamp = appointmentTimestamp(left.appointmentDate);
  const rightTimestamp = appointmentTimestamp(right.appointmentDate);
  if (leftTimestamp === null) return rightTimestamp === null ? 0 : 1;
  if (rightTimestamp === null) return -1;
  return leftTimestamp - rightTimestamp;
}

export function DashboardToday({
  nextAppointment,
  appointments,
  canConsult,
  canOpenPatient,
  onOpenAppointment,
}: DashboardTodayProps) {
  const chronologicalAppointments = [...appointments].sort(compareAppointmentDates);

  return (
    <div className="dashboard-primary">
      <Card className="dashboard-now">
        <div className="dashboard-section-heading">
          <div>
            <span className="dashboard-section-kicker">Ahora</span>
            <h2>Próxima atención</h2>
          </div>
          <AgendaLink className="btn sm ghost" />
        </div>

        {nextAppointment ? (
          <div className="dashboard-now-body">
            <span className="avatar dashboard-avatar">
              <Icon name="user" size={19} />
            </span>
            <div className="dashboard-appointment-summary">
              <div className="dashboard-patient-line">
                <strong>{nextAppointment.patient?.name ?? 'Paciente'}</strong>
                {nextAppointment.patient?.isFirstVisit && (
                  <span className="badge accent plain">1ª visita</span>
                )}
              </div>
              <span className="dashboard-appointment-meta">
                <span className="mono">{formatTime(nextAppointment.appointmentDate)}</span>
                <span>CI {nextAppointment.patient?.idNumber ?? '—'}</span>
                {nextAppointment.type && <span>{nextAppointment.type}</span>}
              </span>
              <StatusBadge
                status={nextAppointment.status}
                label={nextAppointment.statusLabel}
              />
            </div>
            <AppointmentAction
              appointment={nextAppointment}
              canConsult={canConsult}
              canOpenPatient={canOpenPatient}
              onOpenAppointment={onOpenAppointment}
            />
          </div>
        ) : (
          <EmptyState
            icon="cal"
            message={
              appointments.length === 0
                ? 'No hay citas para hoy.'
                : 'No hay más citas programadas para hoy.'
            }
            action={
              <AgendaLink className="btn sm" />
            }
          />
        )}
      </Card>

      <Card className="dashboard-next" padded={false}>
        <div className="card-head">
          <div>
            <span className="dashboard-section-kicker">Después</span>
            <h3>Agenda de hoy</h3>
          </div>
          <AgendaLink className="btn sm ghost" />
        </div>

        {chronologicalAppointments.length === 0 ? (
          <EmptyState
            icon="cal"
            message="No hay citas para hoy."
            action={
              <AgendaLink className="btn sm" />
            }
          />
        ) : (
          <ol className="dashboard-appointment-list">
            {chronologicalAppointments.map((appointment) => (
              <li key={appointment.id} className="dashboard-appointment-row">
                <time className="mono dashboard-appointment-time">
                  {formatTime(appointment.appointmentDate)}
                </time>
                <span className="avatar-sm dashboard-avatar">
                  <Icon name="user" size={15} />
                </span>
                <div className="dashboard-appointment-summary">
                  <div className="dashboard-patient-line">
                    <strong>{appointment.patient?.name ?? '—'}</strong>
                    {appointment.patient?.isFirstVisit && (
                      <span className="badge accent plain">1ª visita</span>
                    )}
                  </div>
                  <span className="dashboard-appointment-meta">
                    <span>CI {appointment.patient?.idNumber ?? '—'}</span>
                    {appointment.type && <span>{appointment.type}</span>}
                  </span>
                </div>
                <StatusBadge status={appointment.status} label={appointment.statusLabel} />
                <AppointmentAction
                  appointment={appointment}
                  canConsult={canConsult}
                  canOpenPatient={canOpenPatient}
                  onOpenAppointment={onOpenAppointment}
                />
              </li>
            ))}
          </ol>
        )}
      </Card>
    </div>
  );
}
