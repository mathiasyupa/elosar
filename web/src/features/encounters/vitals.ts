/** Vital-sign helpers for the encounter screen. Pure on purpose: the arithmetic
 *  is the part worth testing without mounting the whole consultation. */

export const VITAL_FIELDS = [
  ['weight_kg', 'Peso (kg)'],
  ['height_cm', 'Talla (cm)'],
  ['bp_sys', 'PA sistólica'],
  ['bp_dia', 'PA diastólica'],
  ['hr', 'FC (lpm)'],
  ['temp', 'Temp (°C)'],
  ['spo2', 'SpO₂ (%)'],
  ['rr', 'FR (rpm)'],
] as const;

export type VitalKey = (typeof VITAL_FIELDS)[number][0];

/** Vitals that change every visit — show last value as hint only, do not pre-fill. */
export const ACUTE_VITAL_KEYS: VitalKey[] = ['bp_sys', 'bp_dia', 'hr', 'temp', 'spo2', 'rr'];

export const EMPTY_VITALS: Record<VitalKey, string> = {
  weight_kg: '',
  height_cm: '',
  bp_sys: '',
  bp_dia: '',
  hr: '',
  temp: '',
  spo2: '',
  rr: '',
};

export function formatVitalHint(value: string | number | null | undefined, suffix = ''): string | null {
  if (value == null || value === '') return null;
  return `${value}${suffix}`;
}

export function computeBmi(weightKg: string, heightCm: string): string | null {
  const w = Number.parseFloat(weightKg);
  const h = Number.parseFloat(heightCm) / 100;
  if (!Number.isFinite(w) || !Number.isFinite(h) || h <= 0 || w <= 0) return null;
  return (w / (h * h)).toFixed(1);
}

/** WHO BMI bands, as an adult classification.
 *
 *  Derived from the number rather than asked for: the value is already computed,
 *  so a separate dropdown could only ever disagree with it. Cut points are the
 *  WHO's, which is also what the MSP uses.
 *
 *  Not valid for children — BMI in paediatrics is read against age/sex
 *  percentiles, so the caller must skip this for a paediatric consultation.
 */
export function bmiClassification(bmi: string | null): string | null {
  if (!bmi) return null;
  const value = Number.parseFloat(bmi);
  if (!Number.isFinite(value)) return null;

  if (value < 18.5) return 'Bajo peso';
  if (value < 25) return 'Normal';
  if (value < 30) return 'Sobrepeso';
  if (value < 35) return 'Obesidad grado I (leve)';
  if (value < 40) return 'Obesidad grado II (moderada)';
  return 'Obesidad grado III (severa)';
}
