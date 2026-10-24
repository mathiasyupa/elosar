import { isSameMonth, isToday } from 'date-fns';
import type { Appointment, TeamMember } from '@/api/types';
import { type AgendaColorMode, appointmentsForDay, fmt, getAppointmentColorClass } from './calendar';

interface Props {
  anchor: Date;
  days: Date[];
  appointments: Appointment[];
  doctors?: TeamMember[];
  colorMode?: AgendaColorMode;
  onDayClick: (day: Date) => void;
  onAppointmentClick: (appt: Appointment) => void;
}

const WEEKDAYS = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
const MAX_VISIBLE = 3;

export function MonthView({
  anchor,
  days,
  appointments,
  doctors,
  colorMode = 'status',
  onDayClick,
  onAppointmentClick,
}: Props) {
  return (
    <div className="card month">
      <div className="month-head">
        {WEEKDAYS.map((d) => (
          <div key={d}>{d}</div>
        ))}
      </div>
      <div className="month-grid">
        {days.map((day) => {
          const dayAppts = appointmentsForDay(appointments, day);
          const muted = !isSameMonth(day, anchor);
          return (
            <div key={day.toISOString()} className={`month-cell ${muted ? 'muted' : ''}`}>
              <button
                type="button"
                className={`month-daynum ${isToday(day) ? 'today' : ''}`}
                onClick={() => onDayClick(day)}
              >
                {fmt.dayNum(day)}
              </button>
              <div className="month-events">
                {dayAppts.slice(0, MAX_VISIBLE).map((appt) => {
                  const colorClass = getAppointmentColorClass(appt, colorMode, doctors);
                  return (
                    <button
                      key={appt.id}
                      type="button"
                      className={`month-event ${colorClass}`}
                      onClick={() => onAppointmentClick(appt)}
                      title={`${fmt.time(appt.appointment_date)} ${appt.patient?.name ?? ''}${appt.patient?.id_number ? ` · CI ${appt.patient.id_number}` : ''}${appt.patient?.is_first_visit ? ' · 1ª visita' : ''}`}
                    >
                      <i />
                      <span>{fmt.time(appt.appointment_date)} {appt.patient?.name ?? 'Paciente'}</span>
                      {appt.patient?.is_first_visit && <span className="ev-first">1ª</span>}
                    </button>
                  );
                })}
                {dayAppts.length > MAX_VISIBLE && (
                  <button type="button" className="month-more" onClick={() => onDayClick(day)}>
                    +{dayAppts.length - MAX_VISIBLE} más
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

