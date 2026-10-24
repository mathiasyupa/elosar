import {
  addDays,
  addMonths,
  addWeeks,
  eachDayOfInterval,
  endOfMonth,
  endOfWeek,
  format,
  isSameDay,
  parseISO,
  startOfMonth,
  startOfWeek,
} from 'date-fns';
import { es } from 'date-fns/locale';
import type { Appointment, TeamMember } from '@/api/types';

export type CalendarView = 'day' | 'week' | 'month';
export type AgendaColorMode = 'status' | 'type' | 'doctor';
export type AgendaRangeMode = 'auto' | 'standard' | 'full';

// Visible default time window for the day/week grid.
export const DEFAULT_START_HOUR = 7;
export const DEFAULT_END_HOUR = 21;
export const DAY_START_HOUR = DEFAULT_START_HOUR;
export const DAY_END_HOUR = DEFAULT_END_HOUR;
// 72 px: a 30-minute visit shows the name AND the cédula; at 56 the agenda read as a
// wall of name tags stuck together.
export const HOUR_HEIGHT = 72; // px per hour
/** Air between back-to-back visits, so 9:00 and 9:30 read as two blocks. */
const EVENT_GAP = 3;

export const hourLabels = Array.from(
  { length: DEFAULT_END_HOUR - DEFAULT_START_HOUR + 1 },
  (_, i) => DEFAULT_START_HOUR + i,
);

/** Calculate start and end hours dynamically based on loaded appointments and mode. */
export function getHourRange(
  appointments: Appointment[] = [],
  mode: AgendaRangeMode = 'auto',
): { startHour: number; endHour: number } {
  if (mode === 'full') {
    return { startHour: 0, endHour: 24 };
  }

  let startHour = DEFAULT_START_HOUR;
  let endHour = DEFAULT_END_HOUR;

  if (mode === 'auto') {
    for (const appt of appointments) {
      if (!appt.appointment_date) continue;
      const start = parseISO(appt.appointment_date);
      const apptStartHour = start.getHours();
      const duration = appt.duration_minutes ?? 30;
      const apptEndMinutes = apptStartHour * 60 + start.getMinutes() + duration;
      const apptEndHour = Math.ceil(apptEndMinutes / 60);

      if (apptStartHour < startHour) {
        startHour = Math.max(0, apptStartHour);
      }
      if (apptEndHour > endHour) {
        endHour = Math.min(24, apptEndHour);
      }
    }
  }

  return { startHour, endHour };
}

export function getHourLabels(startHour: number, endHour: number): number[] {
  return Array.from(
    { length: Math.max(1, endHour - startHour + 1) },
    (_, i) => startHour + i,
  );
}

const MONDAY = { weekStartsOn: 1 } as const;

/** Inclusive list of dates shown for the given view (the day/week columns, or
 *  the 6-week month matrix). */
export function visibleDays(view: CalendarView, anchor: Date): Date[] {
  if (view === 'day') return [anchor];
  if (view === 'week') {
    const start = startOfWeek(anchor, MONDAY);
    return Array.from({ length: 7 }, (_, i) => addDays(start, i));
  }
  // month: full weeks covering the month
  const start = startOfWeek(startOfMonth(anchor), MONDAY);
  const end = endOfWeek(endOfMonth(anchor), MONDAY);
  return eachDayOfInterval({ start, end });
}

/** Query range (from/to as YYYY-MM-DD) to load every appointment for the view. */
export function rangeFor(view: CalendarView, anchor: Date): { from: string; to: string } {
  const days = visibleDays(view, anchor);
  return {
    from: format(days[0]!, 'yyyy-MM-dd'),
    to: format(days[days.length - 1]!, 'yyyy-MM-dd'),
  };
}

export function shiftDate(view: CalendarView, anchor: Date, dir: -1 | 1): Date {
  if (view === 'day') return addDays(anchor, dir);
  if (view === 'week') return addWeeks(anchor, dir);
  return addMonths(anchor, dir);
}

/** Top offset + height (px) for an appointment inside a day column. */
export function blockGeometry(
  appt: Appointment,
  startHour = DEFAULT_START_HOUR,
): { top: number; height: number } {
  const start = appt.appointment_date ? parseISO(appt.appointment_date) : new Date();
  const minutesFromStart = (start.getHours() - startHour) * 60 + start.getMinutes();
  const duration = appt.duration_minutes ?? 30;
  return {
    top: (minutesFromStart / 60) * HOUR_HEIGHT,
    height: Math.max((duration / 60) * HOUR_HEIGHT - EVENT_GAP, 24),
  };
}

/** Vertical px offset of the "now" line within the grid (null if out of window). */
export function nowOffset(
  now = new Date(),
  startHour = DEFAULT_START_HOUR,
  endHour = DEFAULT_END_HOUR,
): number | null {
  const minutes = (now.getHours() - startHour) * 60 + now.getMinutes();
  if (minutes < 0 || now.getHours() >= endHour) return null;
  return (minutes / 60) * HOUR_HEIGHT;
}

export function appointmentsForDay(appts: Appointment[], day: Date): Appointment[] {
  return appts
    .filter((a) => a.appointment_date && isSameDay(parseISO(a.appointment_date), day))
    .sort((a, b) => (a.appointment_date ?? '').localeCompare(b.appointment_date ?? ''));
}

/** Build an ISO-ish local datetime string for a clicked slot. */
export function slotDateTime(day: Date, hour: number, minute = 0): { date: string; time: string } {
  return {
    date: format(day, 'yyyy-MM-dd'),
    time: `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`,
  };
}

export const fmt = {
  dayName: (d: Date) => format(d, 'EEE', { locale: es }),
  dayNum: (d: Date) => format(d, 'd'),
  monthYear: (d: Date) => format(d, 'MMMM yyyy', { locale: es }),
  longDay: (d: Date) => format(d, "EEEE d 'de' MMMM", { locale: es }),
  time: (iso: string | null) => (iso ? format(parseISO(iso), 'HH:mm') : ''),
  hourLabel: (h: number) => `${String(h).padStart(2, '0')}:00`,
};

export const statusClass: Record<string, string> = {
  scheduled: 'sched',
  completed: 'done',
  cancelled: 'cancel',
};

const DOCTOR_COLORS = ['color-teal', 'color-violet', 'color-amber', 'color-blue', 'color-green', 'color-red'];

const CUSTOM_COLORS_KEY = 'elosar_appointment_colors';

export function getAppointmentCustomColor(appointmentId: number): string | null {
  try {
    const raw = localStorage.getItem(CUSTOM_COLORS_KEY);
    if (!raw) return null;
    const map = JSON.parse(raw) as Record<string, string>;
    return map[String(appointmentId)] ?? null;
  } catch {
    return null;
  }
}

export function setAppointmentCustomColor(appointmentId: number, colorClass: string | null): void {
  try {
    const raw = localStorage.getItem(CUSTOM_COLORS_KEY);
    const map = raw ? (JSON.parse(raw) as Record<string, string>) : {};
    if (colorClass) {
      map[String(appointmentId)] = colorClass;
    } else {
      delete map[String(appointmentId)];
    }
    localStorage.setItem(CUSTOM_COLORS_KEY, JSON.stringify(map));
  } catch {
    // Ignore localStorage unavailable
  }
}

export function getAppointmentColorClass(
  appt: Appointment,
  colorMode: AgendaColorMode = 'status',
  doctors?: TeamMember[],
): string {
  // Check if user manually picked a color for this specific appointment
  const custom = getAppointmentCustomColor(appt.id);
  if (custom) {
    return custom;
  }

  if (colorMode === 'doctor' && appt.doctor_id && doctors && doctors.length > 0) {
    const idx = doctors.findIndex((d) => d.doctor_id === appt.doctor_id || d.id === appt.doctor_id);
    if (idx !== -1) {
      return DOCTOR_COLORS[idx % DOCTOR_COLORS.length]!;
    }
  }

  if (colorMode === 'type' && appt.type) {
    const t = appt.type.toLowerCase();
    if (t.includes('consult') || t.includes('consulta')) return 'color-teal';
    if (t.includes('control') || t.includes('seguimiento') || t.includes('follow')) return 'color-green';
    if (t.includes('proced') || t.includes('procedure') || t.includes('examen')) return 'color-amber';
    if (t.includes('sin cita') || t.includes('walk_in') || t.includes('walk')) return 'color-violet';
    if (t.includes('urgenc') || t.includes('emerg') || t.includes('cirug')) return 'color-red';
    return 'color-violet';
  }

  return statusClass[appt.status] ?? 'sched';
}


