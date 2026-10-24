// Click-to-chat WhatsApp helpers. No official API / credentials: we build a
// wa.me deep link with a pre-written message and the user taps Send. Designed so
// an automated channel (WhatsApp Business API) could later replace the link
// without changing call sites.

/**
 * Normalize an Ecuadorian phone to the wa.me digit format (`5939XXXXXXXX`).
 * Accepts `09XXXXXXXX`, `9XXXXXXXX`, `+593…`, `00593…`. Returns null when the
 * number can't form a plausible mobile (so callers can disable the button).
 */
export function toEcuadorWhatsApp(phone: string | null | undefined): string | null {
  if (!phone) return null;
  let d = phone.replace(/\D/g, '');
  if (!d) return null;
  if (d.startsWith('00')) d = d.slice(2);

  if (d.startsWith('593')) {
    // already country-coded
  } else if (d.startsWith('0')) {
    d = '593' + d.slice(1);
  } else if (d.length === 9 && d.startsWith('9')) {
    d = '593' + d;
  } else {
    d = '593' + d;
  }

  const rest = d.slice(3);
  // Ecuador mobiles are 9 digits after the country code; reject placeholders.
  if (rest.length < 9 || /^0+$/.test(rest)) return null;
  return d;
}

/** Build a wa.me link with a pre-filled message, or null if the phone is invalid. */
export function whatsappLink(phone: string | null | undefined, message: string): string | null {
  const num = toEcuadorWhatsApp(phone);
  if (!num) return null;
  return `https://wa.me/${num}?text=${encodeURIComponent(message)}`;
}

/**
 * The commercial contact link, for a workspace whose trial ended and wants to
 * be activated. Deliberately generic: no name, workspace, clinical or fiscal
 * data ever goes into this URL. Returns null when it is not configured, so
 * callers render nothing rather than a broken link.
 */
export function commercialWhatsAppUrl(): string | null {
  const raw = import.meta.env.VITE_ELOSAR_COMMERCIAL_WHATSAPP_URL;

  if (typeof raw !== 'string' || raw.trim() === '') return null;

  try {
    const url = new URL(raw.trim());

    return url.protocol === 'https:' ? url.toString() : null;
  } catch {
    return null;
  }
}

/** First name only — friendlier in a greeting. */
function firstName(name: string): string {
  const first = name.trim().split(/\s+/)[0] ?? name;

  // Registries often hold names in capitals ("KAROLAY"): "Karolay" reads like
  // a person wrote it.
  return first.charAt(0).toLocaleUpperCase('es') + first.slice(1).toLocaleLowerCase('es');
}

// ── Message templates (Spanish, Ecuador market) ──────────────────────────────

export function birthdayMessage(name: string): string {
  return `¡Feliz cumpleaños, ${firstName(name)}! Todo nuestro equipo te desea un día lleno de alegría, salud y bienestar. Gracias por confiar en nosotros: es un gusto cuidarte. Un abrazo grande.`;
}

export function anniversaryMessage(name: string, years: number): string {
  const y = `${years} año${years === 1 ? '' : 's'}`;
  return `¡Hola, ${firstName(name)}! Hoy se cumple${years === 1 ? '' : 'n'} ${y} desde tu primera consulta con nosotros. Gracias por confiar en nuestro cuidado todo este tiempo.`;
}

export function welcomeMessage(name: string): string {
  return `¡Hola, ${firstName(name)}! Gracias por elegirnos para tu atención médica. Quedamos atentos para acompañarte en tu salud.`;
}

/** Reminder for a control the doctor ordered and the patient never booked. */
export function followUpMessage(name: string, reason?: string | null): string {
  const why = reason?.trim() ? ` para ${reason.trim().toLowerCase()}` : '';
  return `Hola, ${firstName(name)}. Le escribimos del consultorio: en su última consulta el médico indicó un control${why}. ¿Le agendamos una cita? Estamos para ayudarle.`;
}

export function reactivationMessage(name: string): string {
  return `Hola, ${firstName(name)}. Notamos que ha pasado un tiempo desde tu última visita. ¿Te gustaría agendar un control? Estamos para ayudarte.`;
}

interface ApptInfo {
  name: string;
  dateLabel: string;
  timeLabel: string;
  doctorName?: string | null;
}

export function apptConfirmationMessage({ name, dateLabel, timeLabel, doctorName }: ApptInfo): string {
  const withDoctor = doctorName ? ` con ${doctorName}` : '';
  return `¡Hola, ${firstName(name)}! Tu cita quedó agendada para el ${dateLabel} a las ${timeLabel}${withDoctor}. Por favor llega 10 minutos antes. Si necesitas reprogramar, escríbenos.`;
}

export function apptReminderMessage({ name, dateLabel, timeLabel, doctorName }: ApptInfo): string {
  const withDoctor = doctorName ? ` con ${doctorName}` : '';
  return `¡Hola, ${firstName(name)}! Te recordamos tu cita médica para el ${dateLabel} a las ${timeLabel}${withDoctor}. Por favor llega 10 minutos antes. Si no puedes asistir, avísanos.`;
}

export function apptRescheduleMessage({ name, dateLabel, timeLabel, doctorName }: ApptInfo): string {
  const withDoctor = doctorName ? ` con ${doctorName}` : '';
  return `¡Hola, ${firstName(name)}! Te informamos que tu cita médica ha sido reprogramada para el ${dateLabel} a las ${timeLabel}${withDoctor}. Por favor confirma si te queda bien este nuevo horario. ¡Gracias!`;
}

