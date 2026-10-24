import { useId, useState } from 'react';
import { Icon } from '@/components/Icon';
import {
  SCORE_MAMA_LEVEL_LABELS,
  scoreMama,
  type ConsciousnessLevel,
  type OxygenTherapy,
} from './scoreMama';

/** Los tres parámetros que el Score MAMÁ necesita y los signos vitales no traían. */
export interface ScoreMamaCapture {
  oxygen_therapy: OxygenTherapy | null;
  consciousness_level: ConsciousnessLevel | null;
  /** Texto porque viene de un input; se convierte al enviar. */
  urine_output_ml_h: string;
  urine_output_not_quantifiable: boolean;
}

export const EMPTY_SCORE_MAMA: ScoreMamaCapture = {
  oxygen_therapy: null,
  consciousness_level: null,
  urine_output_ml_h: '',
  urine_output_not_quantifiable: false,
};

const OXYGEN_THERAPY_LABELS: Record<OxygenTherapy, string> = {
  ambient_air: 'Aire ambiente',
  nasal_cannula: 'Catéter nasal',
  mask_or_higher: 'Máscara o superior',
};

const CONSCIOUSNESS_LABELS: Record<ConsciousnessLevel, string> = {
  alert: 'Alerta',
  responds_to_voice: 'Responde a la voz',
  responds_to_pain: 'Responde al dolor',
  unresponsive: 'No responde',
};

/** Los seis signos que la pantalla ya captura, tal como los guarda en su estado. */
export interface MeasuredVitals {
  temp?: string;
  bp_sys?: string;
  bp_dia?: string;
  hr?: string;
  rr?: string;
  spo2?: string;
}

interface Props {
  value: ScoreMamaCapture;
  onChange: (value: ScoreMamaCapture) => void;
  measured: MeasuredVitals;
  /** Control prenatal: se despliega solo y no se puede cerrar. */
  alwaysOn?: boolean;
}

const num = (v?: string): number | null => {
  if (v == null || v.trim() === '') return null;
  const n = Number.parseFloat(v);
  return Number.isFinite(n) ? n : null;
};

/**
 * Score MAMÁ dentro de la toma de signos vitales.
 *
 * **Este puntaje es una vista previa, no el dato.** Lo que queda en la historia lo
 * calcula el servidor con los valores que recibe (`ScoreMama` en el backend); aquí
 * se muestra para que quien está tomando los signos vea la alerta mientras aún
 * tiene a la paciente delante, no después de guardar.
 *
 * Aparece solo cuando toca: automático en control prenatal, y con un interruptor
 * en cualquier otra consulta — porque el instrumento también cubre a la puérpera,
 * que no llega etiquetada como prenatal. Pedirle gasto urinario a cada paciente de
 * rutina es la forma más rápida de que el campo se empiece a rellenar en falso.
 */
export function ScoreMamaFields({ value, onChange, measured, alwaysOn = false }: Props) {
  const uid = useId();
  const [manuallyOn, setManuallyOn] = useState(false);
  const open = alwaysOn || manuallyOn;

  const set = <K extends keyof ScoreMamaCapture>(key: K, v: ScoreMamaCapture[K]) =>
    onChange({ ...value, [key]: v });

  if (!open) {
    return (
      <label className="sm-toggle">
        <input
          type="checkbox"
          checked={false}
          onChange={() => setManuallyOn(true)}
        />
        <span>
          Aplicar Score MAMÁ
          <span className="muted"> · alerta materna en gestantes y puérperas</span>
        </span>
      </label>
    );
  }

  const result = scoreMama({
    temperature: num(measured.temp),
    systolic: num(measured.bp_sys),
    diastolic: num(measured.bp_dia),
    heartRate: num(measured.hr),
    respiratoryRate: num(measured.rr),
    oxygenSaturation: num(measured.spo2),
    oxygenTherapy: value.oxygen_therapy,
    consciousness: value.consciousness_level,
    urineOutput: value.urine_output_not_quantifiable
      ? { kind: 'not_quantifiable' }
      : num(value.urine_output_ml_h) != null
        ? { kind: 'measured', mlPerHour: num(value.urine_output_ml_h)! }
        : null,
  });

  return (
    <div className="score-mama">
      <h5>
        Score MAMÁ
        <span className="muted"> · MSP 2025</span>
      </h5>

      <div className="grid sm-grid">
        <div>
          <label className="field-label" htmlFor={`${uid}-o2`}>Oxigenoterapia</label>
          <select
            id={`${uid}-o2`}
            className="field"
            value={value.oxygen_therapy ?? ''}
            onChange={(e) => set('oxygen_therapy', (e.target.value || null) as OxygenTherapy | null)}
          >
            <option value="">Sin registrar</option>
            {(Object.keys(OXYGEN_THERAPY_LABELS) as OxygenTherapy[]).map((k) => (
              <option key={k} value={k}>{OXYGEN_THERAPY_LABELS[k]}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="field-label" htmlFor={`${uid}-cons`}>Nivel de consciencia</label>
          <select
            id={`${uid}-cons`}
            className="field"
            value={value.consciousness_level ?? ''}
            onChange={(e) =>
              set('consciousness_level', (e.target.value || null) as ConsciousnessLevel | null)
            }
          >
            <option value="">Sin registrar</option>
            {(Object.keys(CONSCIOUSNESS_LABELS) as ConsciousnessLevel[]).map((k) => (
              <option key={k} value={k}>{CONSCIOUSNESS_LABELS[k]}</option>
            ))}
          </select>
        </div>

        <div>
          <label className="field-label" htmlFor={`${uid}-urine`}>Gasto urinario (mL/h)</label>
          <input
            id={`${uid}-urine`}
            className="field mono"
            inputMode="decimal"
            value={value.urine_output_ml_h}
            disabled={value.urine_output_not_quantifiable}
            onChange={(e) => set('urine_output_ml_h', e.target.value)}
          />
          {/* "No cuantificable" NO es lo mismo que no medido: puntúa 0 y es lo
              normal en ambulatorio, sin sonda vesical. */}
          <label className="sm-inline">
            <input
              type="checkbox"
              checked={value.urine_output_not_quantifiable}
              onChange={(e) =>
                onChange({
                  ...value,
                  urine_output_not_quantifiable: e.target.checked,
                  urine_output_ml_h: e.target.checked ? '' : value.urine_output_ml_h,
                })
              }
            />
            No cuantificable (sin sonda / ambulatoria)
          </label>
        </div>
      </div>

      <div className={`sm-result sm-${result.level}`}>
        <span className="sm-total">{result.total}</span>
        <span className="sm-meaning">
          <b>{SCORE_MAMA_LEVEL_LABELS[result.level]}</b>
          {result.level === 'emergency' && result.hasParameterAtThree && !(result.total >= 5) && (
            <span className="muted">
              {' '}Un solo parámetro en 3 ya es emergencia, aunque el total sea bajo.
            </span>
          )}
          {!result.complete && (
            <span className="muted">
              {' '}Puntaje parcial: faltan {result.missing.length} de 9 parámetros.
            </span>
          )}
        </span>
        {result.level === 'emergency' && <Icon name="alert" size={18} />}
      </div>
    </div>
  );
}
