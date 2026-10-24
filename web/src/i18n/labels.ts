// Spanish labels for values the backend stores/returns in English (enum-ish
// columns and JSON keys). The DB stays English by convention; these map raw
// values to Spanish at display time so a Spanish-only user never sees English.

export const GENDER_LABELS: Record<string, string> = {
  male: 'Masculino',
  female: 'Femenino',
  other: 'Otro',
  no_especificado: 'No especificado',
};

export const CIVIL_STATUS_LABELS: Record<string, string> = {
  single: 'Soltero/a',
  married: 'Casado/a',
  common_law: 'Unión libre',
  divorced: 'Divorciado/a',
  widowed: 'Viudo/a',
  separated: 'Separado/a',
};

/** Keys of the patient `personal_background` JSON blob. */
export const PERSONAL_BACKGROUND_LABELS: Record<string, string> = {
  allergies: 'Alergias',
  surgeries: 'Cirugías',
  current_medication: 'Medicación actual',
  habits: 'Hábitos',
  notes: 'Notas',
};

/**
 * Appointment `type` is a free-text field (users may type their own), but the
 * seed/walk-in flows write English tokens. Map the known tokens to Spanish and
 * fall back to whatever free text was entered.
 */
/**
 * What the visit is FOR. Keys mirror Appointment::TYPES in the backend —
 * English in the schema, Spanish on screen.
 *
 * `exams` covers the patient who comes only for a laboratory test: a frequent
 * visit that used to require inventing a consultation in order to bill it.
 */
export const APPOINTMENT_TYPE_LABELS: Record<string, string> = {
  consultation: 'Consulta',
  exams: 'Solo exámenes',
  procedure: 'Procedimiento',
  follow_up: 'Seguimiento',
  control: 'Control',
  walk_in: 'Sin cita',
};

/** The subset offered when booking. `walk_in` is not chosen — the server sets
 *  it when a consultation closes with no appointment behind it. */
export const BOOKABLE_APPOINTMENT_TYPES = [
  'consultation',
  'exams',
  'procedure',
  'follow_up',
  'control',
] as const;

export const DOCUMENT_TYPE_LABELS: Record<string, string> = {
  cedula: 'Cédula',
  passport: 'Pasaporte',
  foreign_id: 'ID extranjero',
  other: 'Otro',
};

/** Common countries for identity documents in the region. */
export const DOCUMENT_COUNTRIES: Array<{ code: string; name: string }> = [
  { code: 'EC', name: 'Ecuador' },
  { code: 'VE', name: 'Venezuela' },
  { code: 'CO', name: 'Colombia' },
  { code: 'PE', name: 'Perú' },
  { code: 'AR', name: 'Argentina' },
  { code: 'CL', name: 'Chile' },
  { code: 'BO', name: 'Bolivia' },
  { code: 'BR', name: 'Brasil' },
  { code: 'US', name: 'Estados Unidos' },
  { code: 'MX', name: 'México' },
  { code: 'ES', name: 'España' },
  { code: 'OT', name: 'Otro' },
];

/** Translate a value via a label map, falling back to the raw value. */
export function labelFor(map: Record<string, string>, value: string | null | undefined): string {
  if (value == null || value === '') return '';
  return map[value] ?? value;
}
