import { ecuadorDateInputValue } from './ecuadorDate';

/** Same limit the backend enforces (App\Support\BirthDate). */
export const EARLIEST_BIRTH_DATE = '1900-01-01';

/**
 * Why a birth date is impossible, or null. A typo such as 1087 for 1987 used
 * to be saved and then printed "939 años" everywhere; the form now says so
 * before sending, with the same words the server uses.
 */
export function birthDateProblem(value: string | null | undefined, today = ecuadorDateInputValue()): string | null {
  if (!value) return null;
  if (value < EARLIEST_BIRTH_DATE) return 'La fecha de nacimiento no puede ser anterior a 1900. Revisa el año.';
  if (value > today) return 'La fecha de nacimiento no puede ser posterior a hoy.';
  return null;
}
