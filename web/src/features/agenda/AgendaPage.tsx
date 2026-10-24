import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchAppointments } from '@/api/appointments';
import { fetchClinicTeam } from '@/api/clinic';
import { useAuthStore } from '@/store/auth';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useIsMobile } from '@/lib/useMediaQuery';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { WeekView } from './WeekView';
import { MonthView } from './MonthView';
import { DoctorDayView } from './DoctorDayView';
import { AgendaListView } from './AgendaListView';
import { AppointmentModal } from './NewAppointmentModal';
import { ImportAppointmentsModal } from './ImportAppointmentsModal';
import { WalkInModal } from './WalkInModal';
import { type AgendaColorMode, type AgendaRangeMode, type CalendarView, fmt, getHourRange, rangeFor, shiftDate, slotDateTime, visibleDays } from './calendar';
import { t } from '@/i18n/es';
import type { Appointment, TeamMember } from '@/api/types';
import { QueryError } from '@/components/ui/QueryError';

type AgendaMode = CalendarView | 'list';

const VIEWS: { id: AgendaMode; label: string }[] = [
  { id: 'day', label: 'Día' },
  { id: 'week', label: 'Semana' },
  { id: 'month', label: 'Mes' },
  { id: 'list', label: 'Lista' },
];

export function AgendaPage() {
  // A week/month time-grid cannot be read on a phone — seven columns of
  // 15-minute rows in 375px is unusable — so a small screen starts on the list
  // and is offered only Día / Lista. Widening the window restores the grid.
  const isMobile = useIsMobile();
  const [view, setView] = useState<AgendaMode>(() => (isMobile ? 'list' : 'week'));

  useEffect(() => {
    if (isMobile && (view === 'week' || view === 'month')) setView('list');
  }, [isMobile, view]);

  const availableViews = isMobile ? VIEWS.filter((v) => v.id === 'day' || v.id === 'list') : VIEWS;
  const [anchor, setAnchor] = useState<Date>(() => new Date());
  const [doctorFilter, setDoctorFilter] = useState<number | 'all'>('all');
  const [colorMode, setColorMode] = useState<AgendaColorMode>('type');
  const [rangeMode, setRangeMode] = useState<AgendaRangeMode>('auto');
  const [slot, setSlot] = useState<{ date: string; time: string; doctorId?: number } | null>(null);
  const [editing, setEditing] = useState<Appointment | null>(null);
  const [walkInOpen, setWalkInOpen] = useState(false);
  const [importOpen, setImportOpen] = useState(false);
  const qc = useQueryClient();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const { isClinic, can, workspace, hasRole, isOwner } = useWorkspaceCapabilities();

  // A clinical-only member sees ONLY their own agenda (enforced server-side):
  // holds 'doctor', not owner, and no coordination role (admin/receptionist).
  // Hide the multi-doctor controls (filter, per-doctor day columns) for them.
  const strictDoctor =
    isClinic && hasRole('doctor') && !isOwner && !hasRole('admin') && !hasRole('receptionist');

  // The list view reuses the day's range/nav.
  const calView: CalendarView = view === 'list' ? 'day' : view;
  const days = visibleDays(calView, anchor);
  const range = rangeFor(calView, anchor);

  const { data: team } = useQuery({
    queryKey: ['clinic-team', activeWorkspaceId],
    queryFn: fetchClinicTeam,
    enabled: isClinic,
  });

  const clinicDoctors: TeamMember[] =
    team?.filter((m) => m.role === 'doctor' && m.status === 'active' && m.doctor_id) ?? [];

  const multiDoctorDay = isClinic && !strictDoctor && view === 'day' && clinicDoctors.length > 1;

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['appointments', activeWorkspaceId, range.from, range.to, doctorFilter],
    queryFn: () =>
      fetchAppointments({
        from: range.from,
        to: range.to,
        ...(doctorFilter !== 'all' ? { doctor_id: doctorFilter } : {}),
      }),
  });

  const appointments = data ?? [];
  /**
   * Tocar la agenda mueve más cosas que la agenda.
   *
   * Antes solo se invalidaba `appointments`, así que al crear una cita para hoy
   * el tablero seguía mostrando el conteo anterior hasta recargar la página. Una
   * cita completada además entra en la cola de "atendidos sin factura" que mira
   * caja, y aparece en la sala de espera.
   */
  const invalidate = () => {
    for (const key of [['appointments'], ['dashboard'], ['billables'], ['clinic-queue']]) {
      qc.invalidateQueries({ queryKey: key });
    }
  };

  const { startHour, endHour } = getHourRange(appointments, rangeMode);

  const title =
    calView === 'day' ? fmt.longDay(anchor) : calView === 'month' ? fmt.monthYear(anchor) : `Semana del ${fmt.longDay(days[0]!)}`;

  // Only who schedules opens a new appointment from an empty slot; nursing
  // and others read the agenda without a form they could not save.
  const canSchedule = can('appointments.manage');
  const openSlot = (day: Date, hour: number, doctorId?: number) => {
    if (canSchedule) setSlot({ ...slotDateTime(day, hour), doctorId });
  };
  const openDoctorSlot = (doctorId: number, hour: number) => {
    if (canSchedule) setSlot({ ...slotDateTime(anchor, hour), doctorId });
  };
  const openDay = (day: Date) => {
    setView('day');
    setAnchor(day);
  };

  return (
    <>
      <div className="page-head">
        <div>
          <h1>{t.agenda.title}</h1>
          <p style={{ textTransform: 'capitalize' }}>{title}</p>
        </div>
        <div className="row wrap">
          <div className="pill-row">
            {availableViews.map((v) => (
              <button key={v.id} className={`pill ${view === v.id ? 'active' : ''}`} onClick={() => setView(v.id)}>
                {v.label}
              </button>
            ))}
          </div>

          {/* Color & Range toolbar controls */}
          {view !== 'list' && (
            <div className="row" style={{ gap: 6 }}>
              <select
                className="field"
                style={{ width: 145, padding: '6px 10px', fontSize: 12.5 }}
                value={colorMode}
                onChange={(e) => setColorMode(e.target.value as AgendaColorMode)}
                title="Modo de color de las citas"
              >
                <option value="type">Color: Tipo</option>
                <option value="status">Color: Estado</option>
                {isClinic && clinicDoctors.length > 1 && <option value="doctor">Color: Médico</option>}
              </select>

              <select
                className="field"
                style={{ width: 135, padding: '6px 10px', fontSize: 12.5 }}
                value={rangeMode}
                onChange={(e) => setRangeMode(e.target.value as AgendaRangeMode)}
                title="Rango de horas visibles"
              >
                <option value="auto">Horario: Auto</option>
                <option value="standard">7:00 - 21:00</option>
                <option value="full">24 Horas</option>
              </select>
            </div>
          )}

          {isClinic && !strictDoctor && clinicDoctors.length > 1 && !multiDoctorDay && (
            <select
              className="field"
              style={{ width: 170, padding: '6px 10px' }}
              value={doctorFilter === 'all' ? 'all' : String(doctorFilter)}
              onChange={(e) =>
                setDoctorFilter(e.target.value === 'all' ? 'all' : Number(e.target.value))
              }
            >
              <option value="all">Todos los médicos</option>
              {clinicDoctors.map((d) => (
                <option key={d.doctor_id!} value={d.doctor_id!}>{d.name}</option>
              ))}
            </select>
          )}
          <div className="row" style={{ gap: 4 }}>
            <button className="icon-btn" onClick={() => setAnchor((d) => shiftDate(calView, d, -1))} aria-label="Anterior">
              <Icon name="chevron" size={18} style={{ transform: 'rotate(90deg)' }} />
            </button>
            <button className="btn sm" onClick={() => setAnchor(new Date())}>Hoy</button>
            <button className="icon-btn" onClick={() => setAnchor((d) => shiftDate(calView, d, 1))} aria-label="Siguiente">
              <Icon name="chevron" size={18} style={{ transform: 'rotate(-90deg)' }} />
            </button>
          </div>
          {isClinic && can('appointments.manage') && !strictDoctor && (
            <button className="btn" onClick={() => setWalkInOpen(true)}>
              Sin cita
            </button>
          )}
          {can('appointments.manage') && (
            <button className="btn" onClick={() => setImportOpen(true)}>
              Importar
            </button>
          )}
          {canSchedule && (
            <button className="btn primary" onClick={() => setSlot(slotDateTime(anchor, 9))}>
              <Icon name="plus" size={16} strokeWidth={2.2} /> Nueva cita
            </button>
          )}
        </div>
      </div>

      {isLoading && <Spinner label={t.common.loading} />}
      {(isError || isPaused) && (
        <QueryError error={error} area="la agenda" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
      )}

      {!isLoading && !isError && (
        view === 'list' ? (
          <AgendaListView appointments={appointments} workspaceName={workspace?.name ?? ''} onRowClick={setEditing} />
        ) : multiDoctorDay ? (
          <DoctorDayView
            day={anchor}
            doctors={clinicDoctors}
            appointments={appointments}
            startHour={startHour}
            endHour={endHour}
            colorMode={colorMode}
            onSlotClick={openDoctorSlot}
            onAppointmentClick={setEditing}
          />
        ) : view === 'month' ? (
          <MonthView 
            anchor={anchor} 
            days={days} 
            appointments={appointments} 
            doctors={clinicDoctors}
            colorMode={colorMode}
            onDayClick={openDay} 
            onAppointmentClick={setEditing} 
          />
        ) : (
          <WeekView
            days={days}
            appointments={appointments}
            doctors={clinicDoctors}
            showDoctor={isClinic && !strictDoctor && clinicDoctors.length > 1}
            startHour={startHour}
            endHour={endHour}
            colorMode={colorMode}
            onSlotClick={openSlot}
            onAppointmentClick={setEditing}
          />
        )
      )}

      {slot && (
        <AppointmentModal
          defaultDate={slot.date}
          defaultTime={slot.time}
          defaultDoctorId={slot.doctorId}
          clinicDoctors={isClinic ? clinicDoctors : undefined}
          onClose={() => setSlot(null)}
          onCreated={() => {
            setSlot(null);
            invalidate();
          }}
        />
      )}

      {editing && (
        <AppointmentModal
          appointment={editing}
          clinicDoctors={isClinic ? clinicDoctors : undefined}
          onClose={() => setEditing(null)}
          onCreated={() => {
            setEditing(null);
            invalidate();
          }}
        />
      )}

      {walkInOpen && clinicDoctors.length > 0 && (
        <WalkInModal
          doctors={clinicDoctors}
          onClose={() => setWalkInOpen(false)}
          onCreated={() => {
            setWalkInOpen(false);
            invalidate();
          }}
        />
      )}

      {importOpen && (
        <ImportAppointmentsModal
          onClose={() => setImportOpen(false)}
        />
      )}
    </>
  );
}
