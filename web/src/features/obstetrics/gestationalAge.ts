/** Edad gestacional y fecha probable de parto.
 *
 *  Puro a propósito: la aritmética es la parte que hay que poder probar sin montar
 *  una pantalla, y es la parte donde un error no se ve — una FPP corrida una semana
 *  no parece rota, solo está mal.
 *
 *  **Espejo deliberado de `medico-backend/app/Services/Obstetrics/GestationalAge.php`.**
 *  Lo que se guarda lo calcula el servidor; esto existe para que el médico vea la
 *  edad mientras escribe. Ambas suites afirman los mismos casos resueltos, así que
 *  si una deriva, la otra sigue diciendo la verdad y un test se pone rojo.
 *
 *  Lo que este módulo NO hace, a propósito: elegir entre la FUM y la ecografía. La
 *  GPC de Control Prenatal del MSP dice que datar por FUM tiene un error del 11-22 %
 *  y recomienda ecografía entre las 11 y 14 semanas, pero **no publica un umbral de
 *  redatación** (ver `knowledge/reference/CARNE_PERINATAL.md`). Inventarlo aquí sería
 *  inventar práctica clínica. Se calculan las dos y elige el médico.
 */

export const GESTATIONAL_AGE_PROTOCOL = 'NAEGELE-280';

export type DatingSource = 'lmp' | 'ultrasound' | 'manual';

/** Un embarazo a término, contado desde el primer día de la última menstruación. */
const TERM_DAYS = 280;
const TERM_FROM_DAYS = 259; // 37+0
const POST_TERM_FROM_DAYS = 294; // 42+0

const MS_PER_DAY = 86_400_000;

export interface PregnancyDating {
  /** FUM, en `YYYY-MM-DD`. */
  lmpDate?: string | null;
  /** Fecha en que se tomó la ecografía, en `YYYY-MM-DD`. */
  ultrasoundDate?: string | null;
  ultrasoundWeeks?: number | null;
  ultrasoundDays?: number | null;
  datingSource?: DatingSource | null;
  status?: string | null;
  /** Fecha del desenlace, en `YYYY-MM-DD`. */
  outcomeDate?: string | null;
}

export interface ResolvedDating {
  /** La FPP vigente, en `YYYY-MM-DD`. */
  edd: string | null;
  /** De cuál de las dos salió realmente, que no siempre es la pedida. */
  source: DatingSource | null;
  eddByLmp: string | null;
  eddByUltrasound: string | null;
  /** Con signo: positivo = la ecografía data el embarazo más avanzado que la FUM. */
  discrepancyDays: number | null;
  missing: string[];
}

export interface GestationalAgeResult extends ResolvedDating {
  days: number | null;
  weeks: number | null;
  remainderDays: number | null;
  /** `32+4`, como se escribe en una historia. */
  label: string | null;
  trimester: 1 | 2 | 3 | null;
  isTerm: boolean;
  isPostTerm: boolean;
  isValid: boolean;
}

/** Fechas civiles, siempre. Construirlas como UTC evita que el huso del navegador
 *  mueva un día una fecha que en la historia clínica no tiene hora. */
function parseCivilDate(value?: string | null): number | null {
  if (!value) return null;
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  if (!match) return null;
  const ms = Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
  return Number.isNaN(ms) ? null : ms;
}

function toCivilString(ms: number): string {
  return new Date(ms).toISOString().slice(0, 10);
}

function addDays(ms: number, days: number): number {
  return ms + days * MS_PER_DAY;
}

/** Naegele: la FPP es 280 días después del primer día de la última menstruación. */
export function eddFromLmp(lmpDate: string): string | null {
  const lmp = parseCivilDate(lmpDate);
  return lmp === null ? null : toCivilString(addDays(lmp, TERM_DAYS));
}

/** Una ecografía data el embarazo en el momento en que se tomó: si el feto medía
 *  `w+d` ese día, faltan los días que resten de los 280. */
export function eddFromUltrasound(takenOn: string, weeks: number, days = 0): string | null {
  const on = parseCivilDate(takenOn);
  return on === null ? null : toCivilString(addDays(on, TERM_DAYS - (weeks * 7 + days)));
}

/** Fecha probable de cesárea electiva: 39 semanas, una menos que la FPP.
 *
 *  Es la práctica obstétrica de no programar una cesárea de repetición antes de
 *  las 39 semanas para reducir la morbilidad respiratoria del recién nacido, sin
 *  llegar a que empiece el trabajo de parto espontáneo. Confirmado con el médico
 *  a partir de dos ejemplos reales observados en el sistema que se está
 *  migrando, ambos con exactamente 7 días de diferencia entre FPP y FPC. */
export function electiveCesareanDate(eddDate: string | null): string | null {
  const edd = parseCivilDate(eddDate);
  return edd === null ? null : toCivilString(addDays(edd, -7));
}

export function resolveDating(pregnancy: PregnancyDating): ResolvedDating {
  const lmp = parseCivilDate(pregnancy.lmpDate);
  const byLmp = lmp === null ? null : addDays(lmp, TERM_DAYS);

  const scanOn = parseCivilDate(pregnancy.ultrasoundDate);
  const weeks = pregnancy.ultrasoundWeeks;
  const byScan =
    scanOn !== null && weeks !== null && weeks !== undefined
      ? addDays(scanOn, TERM_DAYS - (weeks * 7 + (pregnancy.ultrasoundDays ?? 0)))
      : null;

  const missing: string[] = [];
  if (byLmp === null) missing.push('lmp_date');
  if (byScan === null) missing.push('ultrasound');

  const requested = pregnancy.datingSource ?? 'lmp';

  // Cae a la datación que sí existe en vez de no reportar FPP: un embarazo datado
  // solo por eco, con el origen todavía en su valor por defecto, sí tiene FPP. Lo
  // que se informa es la usada, no la pedida — se separan justo cuando la pedida
  // no tiene datos detrás.
  let edd: number | null = null;
  let source: DatingSource | null = null;
  if (requested === 'ultrasound' && byScan !== null) {
    edd = byScan;
    source = 'ultrasound';
  } else if (byLmp !== null) {
    edd = byLmp;
    source = 'lmp';
  } else if (byScan !== null) {
    edd = byScan;
    source = 'ultrasound';
  }

  return {
    edd: edd === null ? null : toCivilString(edd),
    source,
    eddByLmp: byLmp === null ? null : toCivilString(byLmp),
    eddByUltrasound: byScan === null ? null : toCivilString(byScan),
    discrepancyDays:
      byLmp !== null && byScan !== null ? Math.round((byLmp - byScan) / MS_PER_DAY) : null,
    missing,
  };
}

/** La edad gestacional a una fecha dada.
 *
 *  Dos reglas heredadas del Score MAMÁ, por la misma razón: un embarazo que no se
 *  puede datar **no** vuelve como cero semanas, vuelve nulo diciendo qué falta; y
 *  una FUM futura vuelve inválida, nunca como una edad negativa.
 *
 *  Un embarazo cerrado se congela en su desenlace: la edad de un embarazo que
 *  terminó no sigue creciendo. */
export function gestationalAgeAt(
  pregnancy: PregnancyDating,
  on: Date | string = new Date(),
): GestationalAgeResult {
  const resolved = resolveDating(pregnancy);

  const asked =
    typeof on === 'string'
      ? parseCivilDate(on)
      : Date.UTC(on.getFullYear(), on.getMonth(), on.getDate());

  const outcome = parseCivilDate(pregnancy.outcomeDate);
  const frozen = pregnancy.status && pregnancy.status !== 'active' && outcome !== null;
  const at = frozen ? outcome : asked;

  const empty: GestationalAgeResult = {
    ...resolved,
    days: null,
    weeks: null,
    remainderDays: null,
    label: null,
    trimester: null,
    isTerm: false,
    isPostTerm: false,
    isValid: false,
  };

  const edd = parseCivilDate(resolved.edd);
  if (edd === null || at === null) return empty;

  // Contar hacia atrás desde la FPP sirve para las dos dataciones sin necesitar
  // una fecha de concepción aparte.
  const days = TERM_DAYS - Math.round((edd - at) / MS_PER_DAY);
  if (days < 0) return empty;

  const weeks = Math.floor(days / 7);
  const remainderDays = days % 7;

  return {
    ...resolved,
    days,
    weeks,
    remainderDays,
    label: `${weeks}+${remainderDays}`,
    trimester: days < 98 ? 1 : days < 196 ? 2 : 3,
    isTerm: days >= TERM_FROM_DAYS,
    isPostTerm: days >= POST_TERM_FROM_DAYS,
    isValid: true,
  };
}
