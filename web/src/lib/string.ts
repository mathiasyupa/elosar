/**
 * Accent-insensitive and case-insensitive search string normalization.
 * e.g. "Sofía" -> "sofia", "Pérez" -> "perez", "José" -> "jose"
 */
export function normalizeText(s: string | null | undefined): string {
  return (s ?? '')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase()
    .trim();
}
