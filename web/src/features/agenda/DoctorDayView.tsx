import { isToday } from 'date-fns';
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
  day: Date;
  doctors: TeamMember[];
  appointments: Appointment[];
  startHour?: number;
  endHour?: number;
  colorMode?: AgendaColorMode;
  onSlotClick: (doctorId: number, hour: number) => void;
  onAppointmentClick: (appt: Appointment) => void;
}

/** Day view with one column per doctor (clinic multi-physician agenda). */
export function DoctorDayView({
  day,
  doctors,
  appointments,
  startHour = DEFAULT_START_HOUR,
  endHour = DEFAULT_END_HOUR,
  colorMode = 'status',
  onSlotClick,
  onAppointmentClick,
}: Props) {
  const gridHeight = (endHour - startHour) * HOUR_HEIGHT;
  const hours = getHourLabels(startHour, endHour);
  const now = nowOffset(new Date(), startHour, endHour);
  const showNow = isToday(day) && now !== null;
  const dayAppts = appointmentsForDay(appointments, day);

  return (
    <div className="cal card" style={{ gridTemplateColumns: `60px repeat(${doctors.length}, 1fr)` }}>
      <div className="cal-corner" />
      {doctors.map((doc) => (
        <div key={doc.doctor_id ?? doc.id} className={`cal-dayhead ${isToday(day) ? 'today' : ''}`}>
          <span className="dn">{doc.name}</span>
          <span className="dd" style={{ fontSize: 11 }}>{fmt.longDay(day)}</span>
        </div>
      ))}

      <div className="cal-gutter" style={{ height: gridHeight }}>
        {hours.slice(0, -1).map((h) => (
          <div key={h} className="cal-hourlabel" style={{ height: HOUR_HEIGHT }}>
            {fmt.hourLabel(h)}
          </div>
        ))}
      </div>

      {doctors.map((doc) => {
        const docId = doc.doctor_id!;
        const appts = dayAppts.filter((a) => a.doctor_id === docId);
        return (
          <div key={docId} className="cal-col" style={{ height: gridHeight }}>
            {hours.slice(0, -1).map((h) => (
              <button
                key={h}
                type="button"
                className="cal-slot"
                style={{ height: HOUR_HEIGHT }}
                onClick={() => onSlotClick(docId, h)}
                aria-label={`Crear cita ${doc.name} ${fmt.hourLabel(h)}`}
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
                </button>
              );
            })}
          </div>
        );
      })}
    </div>
  );
}

