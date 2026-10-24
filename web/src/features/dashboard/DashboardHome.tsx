import { useOpenPatient } from '@/lib/openPatient';
import { useCallback, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { format, isAfter, parseISO } from 'date-fns';
import { es } from 'date-fns/locale';
import { fetchDashboardStats } from '@/api/dashboard';
import { fetchClinicQueue } from '@/api/clinic';
import { fetchPatients, fetchPatientCelebrations } from '@/api/patients';
import { fetchInvoices } from '@/api/invoices';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { KpiMini } from '@/components/ui/Kpi';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { PatientWorkspaceLink } from '@/components/PatientWorkspaceLink';
import { formatDate, formatMoney, t } from '@/i18n/es';
import type { ClinicDashboard, DashboardStats, Invoice, TeamMemberToday } from '@/api/types';
import { QueryError } from '@/components/ui/QueryError';
import { usePageActionRegistration } from '@/components/shell/PageActionContext';
import { OnboardingChecklist } from './OnboardingChecklist';
import { DashboardToday } from './components/DashboardToday';
import { WaitingRoom } from './components/WaitingRoom';
import {
  toDashboardAppointmentView,
  type DashboardAppointmentView,
} from './dashboardView';

/** Normalized dashboard view model, identical regardless of workspace type. */
interface DashView {
  appointments: DashboardAppointmentView[];
  appointmentsToday: number;
  appointmentsPending: number;
  appointmentsCompleted: number;
  patientsTotal: number | null;
  monthRevenue?: string;
  lastMonthRevenue?: string;
  pendingCollections?: string;
  teamToday: TeamMemberToday[];
}

const QUEUE_STATUS_LABELS: Record<string, string> = {
  scheduled: 'Agendada',
  checked_in: 'En sala',
  vitals_done: 'Signos tomados',
  with_doctor: 'Con el médico',
  completed: 'Completada',
  cancelled: 'Cancelada',
  no_show: 'No se presentó',
};

/**
 * Normalize /dashboard/stats into one view model. The endpoint is role-aware:
 * a personal workspace returns the flat DashboardStats; a clinic returns the
 * queue-oriented ClinicDashboard. We map both to the same solo-doctor layout so
 * the dashboard works no matter which shape the (possibly older) backend sends.
 */
function normalizeDashboard(data: DashboardStats | ClinicDashboard | undefined): DashView | null {
  if (!data) return null;

  if (data.mode === 'clinic') {
    const s = data.stats;
    return {
      appointments: (data.queue ?? []).map((q) =>
        toDashboardAppointmentView({
          id: q.id,
          appointment_date: q.time,
          status: q.status,
          status_label: QUEUE_STATUS_LABELS[q.status] ?? q.status,
          patient: q.patient,
        }),
      ),
      appointmentsToday: s?.total ?? 0,
      appointmentsCompleted: s?.completed ?? 0,
      appointmentsPending: Math.max(0, (s?.total ?? 0) - (s?.completed ?? 0) - (s?.no_show ?? 0)),
      patientsTotal: null, // not part of the clinic payload
      monthRevenue: data.billing?.month_revenue,
      lastMonthRevenue: data.billing?.last_month_revenue,
      pendingCollections: data.billing?.pending_collections,
      teamToday: data.team_today ?? [],
    };
  }

  return {
    appointments: (data.todays_appointments ?? []).map(toDashboardAppointmentView),
    appointmentsToday: data.appointments_today ?? 0,
    appointmentsCompleted: data.appointments_completed ?? 0,
    appointmentsPending: data.appointments_pending ?? 0,
    patientsTotal: data.patients_total ?? null,
    monthRevenue: data.month_revenue,
    lastMonthRevenue: data.last_month_revenue,
    pendingCollections: data.pending_collections,
    teamToday: data.team_today ?? [],
  };
}

/** The home dashboard, shared by personal and clinic workspaces. Layout is the
 *  solo-doctor "what do I do now" board (next appointment + today's schedule on
 *  the left; KPIs and pending work on the right rail); a clinic simply adds a
 *  "team on duty" card. Every section is gated by the caller's permissions so a
 *  cashier never sees the schedule and a receptionist never sees revenue. */
export function DashboardHome() {
  const doctor = useAuthStore((s) => s.doctor);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const openQuickSearch = useUiStore((s) => s.openQuickSearch);
  const openPatient = useOpenPatient();
  const { can, isClinic, workspace } = useWorkspaceCapabilities();

  const canAppointments = can('appointments.view');
  const canBilling = can('billing.view');
  const canPatients = can('patients.view');
  const canConsult = can('records.manage');
  // Nursing: whoever takes vitals in a clinic and does not run consultations.
  const isTriage = isClinic && can('vitals.create') && !canConsult;
  const consultationAction = useMemo(
    () =>
      canConsult
        ? {
            id: 'dashboard.new-consultation',
            label: 'Nueva consulta',
            icon: 'plus',
            onInvoke: openQuickSearch,
          }
        : null,
    [canConsult, openQuickSearch],
  );
  usePageActionRegistration(consultationAction);

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['dashboard', activeWorkspaceId],
    queryFn: fetchDashboardStats,
    // En una clínica el tablero resume lo que hacen otras personas: recepción
    // agenda, enfermería hace triaje, el médico cierra consultas. La caché local
    // no se entera de nada de eso, así que se sondea. Un minuto es suficiente
    // para un resumen — la sala de espera, que sí es operativa, va a 30 s.
    // React Query pausa el sondeo con la pestaña oculta.
    refetchInterval: 60_000,
  });

  const { data: queue } = useQuery({
    queryKey: ['clinic-queue', activeWorkspaceId, 'today'],
    queryFn: () => fetchClinicQueue(),
    // Doctors too: the waiting room is shared by the whole clinic.
    enabled: isTriage || (isClinic && canConsult),
    refetchInterval: 30_000,
  });

  const { data: recents } = useQuery({
    queryKey: ['patient-recents', activeWorkspaceId],
    queryFn: () => fetchPatients('', 1, 'recent'),
    enabled: canPatients,
  });

  const { data: invoicesData } = useQuery({
    queryKey: ['invoices', activeWorkspaceId, 'all'],
    queryFn: () => fetchInvoices(1),
    enabled: canBilling,
  });

  const { data: celebrations } = useQuery({
    queryKey: ['patient-celebrations', activeWorkspaceId],
    queryFn: fetchPatientCelebrations,
    enabled: canPatients,
  });
  const birthdayCount = celebrations?.birthdays_today.length ?? 0;

  const view = useMemo(() => normalizeDashboard(data), [data]);

  // Top pending collections: open invoices with the largest outstanding balance.
  const urgent = useMemo<Invoice[]>(() => {
    const open = (invoicesData?.data ?? []).filter(
      (i) => Number(i.amount_due) > 0 && i.status !== 'void' && i.status !== 'cancelled',
    );
    return open.sort((a, b) => Number(b.amount_due) - Number(a.amount_due)).slice(0, 3);
  }, [invoicesData]);

  // Next upcoming appointment today (first scheduled one still ahead of now).
  const nextAppt = useMemo(() => {
    const now = new Date();
    return (view?.appointments ?? [])
      .filter(
        (appointment) =>
          // Signos tomados: already here and ready, whatever time it was booked
          // for — a walk-in's time is «now» and is past a minute later.
          appointment.status === 'vitals_done'
          || (appointment.status === 'scheduled'
            && appointment.appointmentDate
            && isAfter(parseISO(appointment.appointmentDate), now)),
      )
      .sort((left, right) =>
        Number(right.status === 'vitals_done') - Number(left.status === 'vitals_done')
        || (left.appointmentDate ?? '').localeCompare(right.appointmentDate ?? ''),
      )[0] ?? null;
  }, [view]);

  const revTrendText = useMemo(() => {
    if (!view) return undefined;
    const cur = Number(view.monthRevenue ?? 0);
    const prev = Number(view.lastMonthRevenue ?? 0);
    if (prev <= 0) return cur > 0 ? 'primer mes con ingresos' : undefined;
    const pct = Math.round(((cur - prev) / prev) * 100);
    return `${pct >= 0 ? '+' : ''}${pct}% vs mes anterior`;
  }, [view]);

  const teamToday = isClinic ? view?.teamToday ?? [] : [];

  // Only clinicians invoke this callback. The read-only patient action is a
  // named anchor in DashboardToday, so it can preserve the dashboard tab.
  const openAppt = useCallback((patientId: number, appointmentId: number) => {
    if (canConsult) openPatient(patientId, `atencion?appointment=${appointmentId}`);
  }, [canConsult, openPatient]);

  const heading = isClinic ? workspace?.name ?? 'Clínica' : `Hola, ${doctor?.name ?? 'doctor'}`;
  const subheading = isClinic ? 'Resumen de la clínica de hoy' : 'Resumen de tu práctica médica de hoy';
  // La portada del día: fecha completa en el kicker editorial sobre el saludo.
  const dayKicker = format(new Date(), "EEEE d 'de' MMMM", { locale: es });

  const rail = (
    <aside className="dashboard-rail" aria-label="Resumen y pendientes">
      {view && (
        <div className="dashboard-kpi-strip">
          {canAppointments && (
            <KpiMini
              icon="cal"
              label="Citas hoy"
              value={String(view.appointmentsToday)}
              sub={`${view.appointmentsCompleted} hechas · ${view.appointmentsPending} pend.`}
            />
          )}
          {canBilling && (
            <>
              <KpiMini
                icon="money"
                label="Ingresos del mes"
                value={formatMoney(view.monthRevenue)}
                tone="green"
                sub={revTrendText}
              />
              <KpiMini
                icon="card"
                label="Por cobrar"
                value={formatMoney(view.pendingCollections)}
                tone="amber"
              />
            </>
          )}
          {canPatients && view.patientsTotal != null && (
            <KpiMini
              icon="users"
              label="Pacientes"
              value={String(view.patientsTotal)}
              tone="violet"
            />
          )}
        </div>
      )}

      <div className="dashboard-pending">
        {isTriage && (
          <Card padded={false}>
            <div className="card-head">
              <h3>Sala de espera</h3>
              <Link className="btn sm primary" to="/cola">Ir a la sala de espera</Link>
            </div>
            <div className="card-pad dashboard-triage">
              <div>
                <b className="dashboard-triage-n">{queue?.stats.pending_vitals ?? '—'}</b>
                <span className="muted">pendientes de signos vitales</span>
              </div>
              <div>
                <b className="dashboard-triage-n">{queue?.stats.waiting ?? '—'}</b>
                <span className="muted">en sala</span>
              </div>
            </div>
          </Card>
        )}
        {view && canBilling && (
          <Card padded={false}>
            <div className="card-head">
              <h3>Cobros pendientes</h3>
              <Link className="btn sm ghost" to="/facturas">Ver cobros</Link>
            </div>
            {urgent.length === 0 ? (
              <EmptyState icon="check" message="Sin cobros pendientes." />
            ) : (
              <div className="card-pad dashboard-rail-list">
                {urgent.map((invoice) => (
                  <Link key={invoice.id} to={`/facturas/${invoice.id}`} className="urgent-row">
                    <div>
                      <b>{invoice.patient?.name ?? '—'}</b>
                      <span className="muted">{invoice.invoice_number}</span>
                    </div>
                    <b className="mono urgent-amt">{formatMoney(invoice.amount_due)}</b>
                  </Link>
                ))}
              </div>
            )}
          </Card>
        )}

        {view && canPatients && (
          <Card padded={false}>
            <div className="card-head">
              <h3>Pacientes recientes</h3>
              <Link className="btn sm ghost" to="/pacientes">Ver todos</Link>
            </div>
            {(recents?.data.length ?? 0) === 0 ? (
              <EmptyState
                icon="users"
                message="Aún no hay pacientes."
                hint="Es el punto de partida de la historia clínica."
                action={
                  <Link className="btn sm primary" to="/pacientes">
                    <Icon name="plus" size={14} /> Registrar paciente
                  </Link>
                }
              />
            ) : (
              <div className="card-pad dashboard-rail-list">
                {recents!.data.slice(0, 5).map((patient) => (
                  <PatientWorkspaceLink
                    key={patient.id}
                    className="recent-row"
                    patientId={patient.id}
                  >
                    <span className="avatar-sm dashboard-avatar">
                      <Icon name="user" size={15} />
                    </span>
                    <div className="dashboard-row-body">
                      <b>{patient.name}</b>
                      <span className="muted">
                        {patient.last_visit_at
                          ? `Última visita ${formatDate(patient.last_visit_at)}`
                          : 'Sin visitas'}
                      </span>
                    </div>
                    <Icon name="arrow" size={15} />
                  </PatientWorkspaceLink>
                ))}
              </div>
            )}
          </Card>
        )}

        {view && isClinic && teamToday.length > 0 && (
          <Card padded={false}>
            <div className="card-head">
              <h3>Equipo de hoy</h3>
              {can('team.view') && (
                <Link className="btn sm ghost" to="/equipo">Ver equipo</Link>
              )}
            </div>
            <div className="card-pad dashboard-rail-list">
              {teamToday.map((member) => (
                <div key={member.doctor_id} className="recent-row dashboard-team-row">
                  <span className="avatar-sm dashboard-avatar">
                    <Icon name="user" size={15} />
                  </span>
                  <div className="dashboard-row-body">
                    <b>{member.name}</b>
                    <span className="muted">
                      {member.completed}/{member.appointments} atendidas
                    </span>
                  </div>
                </div>
              ))}
            </div>
          </Card>
        )}

        <OnboardingChecklist />

        {view && birthdayCount > 0 && (
          <div className="alert-rail">
            {birthdayCount > 0 && (
              <Link to="/pacientes?view=celebrations" className="alert-pill green">
                <span className="ap-ic"><Icon name="gift" size={16} /></span>
                <span>
                  {birthdayCount} paciente(s) cumplen años hoy — felicítalos por WhatsApp
                </span>
                <Icon name="arrow" size={15} />
              </Link>
            )}
          </div>
        )}
      </div>
    </aside>
  );

  return (
    <>
      <div className="page-head">
        <div>
          <span className="page-kicker">{dayKicker}</span>
          <h1>{heading}</h1>
          <p>{subheading}</p>
        </div>
      </div>

      {isClinic && canConsult && queue && <WaitingRoom rows={queue.appointments} />}

      {isLoading && <Spinner label={t.common.loading} />}
      {(isError || isPaused) && (
        <QueryError error={error} area="el resumen" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
      )}

      <div
        className={`dashboard-workspace ${
          view && canAppointments ? '' : 'dashboard-workspace-rail-only'
        }`.trim()}
      >
        {view && canAppointments && (
          <DashboardToday
            nextAppointment={nextAppt}
            appointments={view.appointments}
            canConsult={canConsult}
            canOpenPatient={canPatients}
            onOpenAppointment={openAppt}
          />
        )}
        {rail}
      </div>
    </>
  );
}
