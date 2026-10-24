import { isToday } from 'date-fns';
import { APPOINTMENT_TYPE_LABELS, labelFor } from '@/i18n/labels';
import type { Appointment, TeamMember } from '@/api/types';
import {
  type AgendaColorMode,
  appointmentsForDay,
  blockGeometry,
  DEFAULT_END_HOUR,
  DEFAULT_START_HOUR,
  fmt,
  getAppointmentColorClass,
  getHourLabels,
  HOUR_HEIGHT,
  nowOffset,
} from './calendar';

interface Props {
  days: Date[];
  appointments: Appointment[];
  doctors?: TeamMember[];
  showDoctor?: boolean;
  startHour?: number;
  endHour?: number;
  colorMode?: AgendaColorMode;
  onSlotClick: (day: Date, hour: number) => void;
  onAppointmentClick: (appt: Appointment) => void;
}

export function WeekView({
  days,
  appointments,
  doctors,
  showDoctor,
  startHour = DEFAULT_START_HOUR,
  endHour = DEFAULT_END_HOUR,
  colorMode = 'status',
  onSlotClick,
  onAppointmentClick,
}: Props) {
  const gridHeight = (endHour - startHour) * HOUR_HEIGHT;
  const hours = getHourLabels(startHour, endHour);
  const now = nowOffset(new Date(), startHour, endHour);

  return (
    <div className="cal card" style={{ gridTemplateColumns: `60px repeat(${days.length}, 1fr)` }}>
      {/* Header row */}
      <div className="cal-corner" />
      {days.map((day) => (
        <div key={day.toISOString()} className={`cal-dayhead ${isToday(day) ? 'today' : ''}`}>
          <span className="dn">{fmt.dayName(day)}</span>
          <span className="dd">{fmt.dayNum(day)}</span>
        </div>
      ))}

      {/* Body: gutter + day columns share one scroll area */}
      <div className="cal-gutter" style={{ height: gridHeight }}>
        {hours.slice(0, -1).map((h) => (
          <div key={h} className="cal-hourlabel" style={{ height: HOUR_HEIGHT }}>
            {fmt.hourLabel(h)}
          </div>
        ))}
      </div>

      {days.map((day) => {
        const appts = appointmentsForDay(appointments, day);
        const showNow = isToday(day) && now !== null;
        return (
          <div key={day.toISOString()} className="cal-col" style={{ height: gridHeight }}>
            {hours.slice(0, -1).map((h) => (
              <button
                key={h}
                type="button"
                className="cal-slot"
                style={{ height: HOUR_HEIGHT }}
                onClick={() => onSlotClick(day, h)}
                aria-label={`Crear cita ${fmt.dayName(day)} ${fmt.hourLabel(h)}`}
              />
            ))}

            {showNow && <div className="cal-nowline" style={{ top: now }} />}

            {appts.map((appt) => {
              const geo = blockGeometry(appt, startHour);
              const colorClass = getAppointmentColorClass(appt, colorMode, doctors);
              return (
                <button
                  key={appt.id}
                  type="button"
                  className={`cal-event ${colorClass}`}
                  style={{ top: geo.top, height: geo.height }}
                  onClick={() => onAppointmentClick(appt)}
                >
                  <b>
                    <span className="mono">{fmt.time(appt.appointment_date)}</span> {appt.patient?.name ?? 'Paciente'}
                    {appt.patient?.is_first_visit && <span className="ev-first">1ª</span>}
                  </b>
                  {appt.patient?.id_number && geo.height > 34 && (
                    <span className="ev-ci">CI {appt.patient.id_number}</span>
                  )}
                  {appt.type && geo.height > 52 && <span>{labelFor(APPOINTMENT_TYPE_LABELS, appt.type)}</span>}
                  {showDoctor && appt.doctor_name && geo.height > 52 && (
                    <span className="ev-ci">{appt.doctor_name}</span>
                  )}
                </button>
              );
            })}
          </div>
        );
      })}
    </div>
  );
}

