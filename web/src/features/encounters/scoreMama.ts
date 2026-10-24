/** Score MAMÁ — el puntaje de alerta materna temprana del MSP de Ecuador.
 *
 *  Puro a propósito: la aritmética es la parte que hay que poder probar sin montar
 *  una pantalla, y es la parte donde un error no se ve pero no suena una alarma.
 *
 *  **La tabla es la del Protocolo Score MAMÁ y Claves Obstétricas 2025 (Acuerdo
 *  Ministerial 00042-2025), transcrita en `knowledge/reference/SCORE_MAMA.md`.**
 *  Esa nota se reconstruyó por coordenadas del PDF y se validó contra el ejemplo
 *  resuelto que trae el propio protocolo. Si hay que cambiar un corte, se cambia
 *  allí primero y se repite esa validación: aquí no se ajusta "a ojo".
 */

export type OxygenTherapy = 'ambient_air' | 'nasal_cannula' | 'mask_or_higher';

export type ConsciousnessLevel =
  | 'alert'
  | 'responds_to_voice'
  | 'responds_to_pain'
  | 'unresponsive';

/** El gasto urinario tiene un estado que no es un número ni una ausencia.
 *
 *  "No cuantificable" (sin sonda vesical, paciente ambulatoria) es el valor NORMAL
 *  de la tabla y puntúa 0. Modelarlo como `null` haría que toda paciente de consulta
 *  externa saliera con la toma incompleta. */
export type UrineOutput =
  | { kind: 'not_quantifiable' }
  | { kind: 'measured'; mlPerHour: number };

export interface ScoreMamaInput {
  /** °C */
  temperature?: number | null;
  /** mmHg */
  systolic?: number | null;
  /** mmHg */
  diastolic?: number | null;
  /** lpm */
  heartRate?: number | null;
  /** rpm */
  respiratoryRate?: number | null;
  /** % */
  oxygenSaturation?: number | null;
  oxygenTherapy?: OxygenTherapy | null;
  consciousness?: ConsciousnessLevel | null;
  urineOutput?: UrineOutput | null;
}

export type ScoreMamaParameter = keyof ScoreMamaInput;

/** Bandas de acción del protocolo. Los pasos concretos cambian según el nivel del
 *  establecimiento, pero las bandas son las mismas en los tres. */
export type ScoreMamaLevel = 'normal' | 'reevaluate' | 'treat_and_refer' | 'emergency';

export interface ScoreMamaResult {
  /** Suma de lo que se pudo puntuar. Un parámetro sin tomar no suma. */
  total: number;
  /** Puntaje por parámetro; `null` en los que no se tomaron. */
  points: Record<ScoreMamaParameter, number | null>;
  /** Parámetros sin valor. Vacío cuando la toma está completa. */
  missing: ScoreMamaParameter[];
  complete: boolean;
  /** Un solo parámetro en 3 ya es emergencia, aunque el total sea bajo. */
  hasParameterAtThree: boolean;
  level: ScoreMamaLevel;
}

/** Un tramo de la tabla: hasta `max` (inclusive) puntúa `points`. */
interface Band {
  max: number;
  points: number;
}

/** Evalúa tramos de menor a mayor; el último es el techo abierto. */
function scoreBands(value: number, bands: Band[], above: number): number {
  for (const band of bands) {
    if (value <= band.max) return band.points;
  }
  return above;
}

const ORDER: ScoreMamaParameter[] = [
  'temperature',
  'systolic',
  'diastolic',
  'heartRate',
  'respiratoryRate',
  'oxygenSaturation',
  'oxygenTherapy',
  'consciousness',
  'urineOutput',
];

const OXYGEN_THERAPY_POINTS: Record<OxygenTherapy, number> = {
  ambient_air: 0,
  nasal_cannula: 2,
  mask_or_higher: 3,
};

const CONSCIOUSNESS_POINTS: Record<ConsciousnessLevel, number> = {
  alert: 0,
  responds_to_voice: 1,
  responds_to_pain: 2,
  unresponsive: 3,
};

function scoreParameter(key: ScoreMamaParameter, input: ScoreMamaInput): number | null {
  switch (key) {
    case 'temperature': {
      const v = input.temperature;
      if (v == null) return null;
      // La columna 2 del lado frío está vacía: 35-35.9 vale 1, no 2.
      return scoreBands(v, [
        { max: 34.99, points: 3 },
        { max: 35.9, points: 1 },
        { max: 37.4, points: 0 },
        { max: 38.2, points: 1 },
        { max: 38.9, points: 2 },
      ], 3);
    }
    case 'systolic': {
      const v = input.systolic;
      if (v == null) return null;
      return scoreBands(v, [
        { max: 70, points: 3 },
        { max: 79, points: 2 },
        { max: 89, points: 1 },
        { max: 139, points: 0 },
        { max: 149, points: 1 },
        { max: 159, points: 2 },
      ], 3);
    }
    case 'diastolic': {
      const v = input.diastolic;
      if (v == null) return null;
      // Sin columnas 3 ni 2 del lado bajo: una diastólica baja aislada vale 1.
      return scoreBands(v, [
        { max: 49, points: 1 },
        { max: 89, points: 0 },
        { max: 99, points: 1 },
        { max: 109, points: 2 },
      ], 3);
    }
    case 'heartRate': {
      const v = input.heartRate;
      if (v == null) return null;
      return scoreBands(v, [
        { max: 40, points: 3 },
        { max: 49, points: 2 },
        { max: 99, points: 0 },
        { max: 109, points: 1 },
        { max: 129, points: 2 },
      ], 3);
    }
    case 'respiratoryRate': {
      const v = input.respiratoryRate;
      if (v == null) return null;
      return scoreBands(v, [
        { max: 10, points: 3 },
        { max: 20, points: 0 },
        { max: 24, points: 1 },
        { max: 29, points: 2 },
      ], 3);
    }
    case 'oxygenSaturation': {
      const v = input.oxygenSaturation;
      if (v == null) return null;
      // Sólo tiene lado bajo: no se penaliza saturar de más.
      return scoreBands(v, [
        { max: 88, points: 3 },
        { max: 90, points: 2 },
        { max: 93, points: 1 },
      ], 0);
    }
    case 'oxygenTherapy': {
      const v = input.oxygenTherapy;
      return v == null ? null : OXYGEN_THERAPY_POINTS[v];
    }
    case 'consciousness': {
      const v = input.consciousness;
      return v == null ? null : CONSCIOUSNESS_POINTS[v];
    }
    case 'urineOutput': {
      const v = input.urineOutput;
      if (v == null) return null;
      if (v.kind === 'not_quantifiable') return 0;
      // El protocolo imprime ≤10 → 3 y ≤30 → 2, y NO imprime celda para un valor
      // medido por encima de 30. Se puntúa 0 por el corte clínico de oliguria
      // (<30 mL/h), que es la columna en la que vive "no cuantificable".
      // Es una inferencia, no un dato de la tabla; está anotada en SCORE_MAMA.md.
      return scoreBands(v.mlPerHour, [
        { max: 10, points: 3 },
        { max: 30, points: 2 },
      ], 0);
    }
  }
}

/**
 * Calcula el Score MAMÁ de una toma de signos.
 *
 * Dos decisiones que no son obvias y que importan clínicamente:
 *
 * 1. **Un parámetro sin tomar no suma cero: no suma.** Contarlo como 0 rebajaría
 *    el puntaje de una paciente precisamente en lo que no se le midió. El
 *    resultado dice qué falta para que quien lo lea sepa cuánto vale.
 * 2. **El nivel máximo no es sólo ≥5.** Cualquier parámetro aislado en 3 es
 *    emergencia obstétrica aunque el total sea 3. Comparar sólo el total contra 5
 *    deja pasar una sistólica de 65 mmHg.
 */
export function scoreMama(input: ScoreMamaInput): ScoreMamaResult {
  const points = {} as Record<ScoreMamaParameter, number | null>;
  const missing: ScoreMamaParameter[] = [];
  let total = 0;
  let hasParameterAtThree = false;

  for (const key of ORDER) {
    const score = scoreParameter(key, input);
    points[key] = score;

    if (score === null) {
      missing.push(key);
      continue;
    }

    total += score;
    if (score === 3) hasParameterAtThree = true;
  }

  return {
    total,
    points,
    missing,
    complete: missing.length === 0,
    hasParameterAtThree,
    level: levelFor(total, hasParameterAtThree),
  };
}

function levelFor(total: number, hasParameterAtThree: boolean): ScoreMamaLevel {
  if (total >= 5 || hasParameterAtThree) return 'emergency';
  if (total >= 2) return 'treat_and_refer';
  if (total === 1) return 'reevaluate';
  return 'normal';
}

/** Qué hacer en cada banda, en el español que el protocolo usa. */
export const SCORE_MAMA_LEVEL_LABELS: Record<ScoreMamaLevel, string> = {
  normal: 'Evaluar factores de riesgo y signos de alarma',
  reevaluate: 'Reevaluar: Score MAMÁ cada 4 horas',
  treat_and_refer: 'Tratar y referir · Score MAMÁ cada 60 minutos',
  emergency: 'Tratar y referir · Score MAMÁ cada 30 minutos · activar clave obstétrica',
};
