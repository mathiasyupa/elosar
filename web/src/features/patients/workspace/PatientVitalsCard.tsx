import { useId, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import {
  createPatientVitalObservation,
  fetchBaselineVitals,
  type PatientVitalObservationInput,
} from '@/api/patients';
import { VITAL_FIELDS, EMPTY_VITALS, type VitalKey } from '@/features/encounters/vitals';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { formatDate } from '@/i18n/es';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { QueryError } from '@/components/ui/QueryError';

const LIMITS: Record<VitalKey | 'head_circumference_cm', [number, number]> = {
  weight_kg: [0, 500],
  height_cm: [0, 300],
  head_circumference_cm: [20, 70],
  bp_sys: [50, 300],
  bp_dia: [30, 200],
  hr: [20, 300],
  temp: [30, 45],
  spo2: [50, 100],
  rr: [5, 80],
};

const FIELD_NAMES: Record<VitalKey | 'head_circumference_cm', keyof PatientVitalObservationInput> = {
  weight_kg: 'weight_kg',
  height_cm: 'height_cm',
  head_circumference_cm: 'head_circumference_cm',
  bp_sys: 'blood_pressure_sys',
  bp_dia: 'blood_pressure_dia',
  hr: 'heart_rate',
  temp: 'temperature',
  spo2: 'oxygen_saturation',
  rr: 'respiratory_rate',
};

type FormValues = Record<VitalKey | 'head_circumference_cm', string>;
const EMPTY_FORM: FormValues = { ...EMPTY_VITALS, head_circumference_cm: '' };

/** Last measured signs and an in-place nursing form independent of appointments. */
export function PatientVitalsCard({ patientId }: { patientId: number }) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const { can } = useWorkspaceCapabilities();
  const canCapture = can('vitals.create');
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const uid = useId();
  const [editing, setEditing] = useState(false);
  const [values, setValues] = useState<FormValues>(EMPTY_FORM);
  const [errors, setErrors] = useState<Partial<Record<keyof FormValues, string>>>({});

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['baseline-vitals', activeWorkspaceId, patientId],
    queryFn: () => fetchBaselineVitals(patientId),
    enabled: canCapture,
  });

  const save = useMutation({
    mutationFn: (payload: PatientVitalObservationInput) => createPatientVitalObservation(patientId, payload),
    onSuccess: (saved) => {
      qc.setQueryData(['baseline-vitals', activeWorkspaceId, patientId], saved);
      void qc.invalidateQueries({ queryKey: ['baseline-vitals', activeWorkspaceId, patientId] });
      setValues(EMPTY_FORM);
      setErrors({});
      setEditing(false);
      toast('Signos vitales guardados en el paciente.', 'success');
    },
    onError: (saveError) => {
      if (isAxiosError(saveError)) {
        const serverErrors = (saveError.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors;
        const next: Partial<Record<keyof FormValues, string>> = {};
        for (const [key, apiKey] of Object.entries(FIELD_NAMES) as [keyof FormValues, string][]) {
          if (serverErrors?.[apiKey]?.[0]) next[key] = serverErrors[apiKey][0];
        }
        setErrors(next);
      }
      toast('No se pudieron guardar los signos vitales. Revisa los datos e inténtalo otra vez.', 'error');
    },
  });

  const submit = () => {
    const nextErrors: Partial<Record<keyof FormValues, string>> = {};
    const payload: PatientVitalObservationInput = {};
    let hasValue = false;

    for (const [key, apiKey] of Object.entries(FIELD_NAMES) as [keyof FormValues, keyof PatientVitalObservationInput][]) {
      const raw = values[key].trim().replace(',', '.');
      if (!raw) continue;
      hasValue = true;
      const number = Number(raw);
      const [min, max] = LIMITS[key];
      if (!Number.isFinite(number) || number < min || number > max) {
        nextErrors[key] = `Ingresa un valor entre ${min} y ${max}.`;
        continue;
      }
      if (['bp_sys', 'bp_dia', 'hr', 'rr'].includes(key) && !Number.isInteger(number)) {
        nextErrors[key] = 'Ingresa un número entero.';
        continue;
      }
      (payload as Record<string, number | string>)[apiKey] =
        ['weight_kg', 'height_cm', 'head_circumference_cm', 'temperature', 'oxygen_saturation'].includes(apiKey) ? raw : number;
    }
    if (!hasValue) nextErrors.weight_kg = 'Ingresa al menos un signo vital.';
    setErrors(nextErrors);
    if (Object.keys(nextErrors).length === 0) save.mutate(payload);
  };

  const pairs: [string, string | null][] = data
    ? [
        ['Peso', data.weight_kg ? `${data.weight_kg} kg` : null],
        ['Talla', data.height_cm ? `${data.height_cm} cm` : null],
        ['Perímetro cefálico', data.head_circumference_cm ? `${data.head_circumference_cm} cm` : null],
        ['PA', data.blood_pressure_sys && data.blood_pressure_dia
          ? `${data.blood_pressure_sys}/${data.blood_pressure_dia} mmHg` : null],
        ['FC', data.heart_rate ? `${data.heart_rate} lpm` : null],
        ['Temp.', data.temperature ? `${data.temperature} °C` : null],
        ['SpO₂', data.oxygen_saturation ? `${data.oxygen_saturation} %` : null],
        ['FR', data.respiratory_rate ? `${data.respiratory_rate} rpm` : null],
      ]
    : [];
  const shown = pairs.filter((pair): pair is [string, string] => pair[1] != null);

  return (
    <div className="patient-vitals-card">
      {canCapture && (
        <div className="patient-vitals-action">
          <button
            className="btn sm"
            type="button"
            onClick={() => { setEditing((current) => !current); setErrors({}); }}
            disabled={save.isPending}
            aria-expanded={editing}
          >
            {editing ? 'Cancelar' : 'Registrar signos'}
          </button>
          <span className="muted">No hace falta crear una cita.</span>
        </div>
      )}

      {editing && canCapture && (
        <div className="patient-vitals-form">
          <p className="muted">Escribe solo los valores medidos ahora. Los datos anteriores no se copiarán.</p>
          <div className="patient-vitals-fields">
            {[...VITAL_FIELDS, ['head_circumference_cm', 'Perímetro cefálico (cm)'] as const].map(([key, label]) => (
              <div key={key}>
                <label className="field-label" htmlFor={`${uid}-${key}`}>{label}</label>
                <input
                  id={`${uid}-${key}`}
                  className="field mono"
                  inputMode="decimal"
                  value={values[key]}
                  aria-invalid={errors[key] ? true : undefined}
                  aria-describedby={errors[key] ? `${uid}-${key}-error` : undefined}
                  onChange={(event) => setValues((current) => ({ ...current, [key]: event.target.value }))}
                />
                {errors[key] && <p className="field-error" id={`${uid}-${key}-error`}>{errors[key]}</p>}
              </div>
            ))}
          </div>
          <button className="btn primary" type="button" onClick={submit} disabled={save.isPending}>
            {save.isPending ? 'Guardando…' : 'Guardar signos vitales'}
          </button>
        </div>
      )}

      {isLoading && <p className="muted">Cargando signos vitales…</p>}
      {(isError || isPaused) && canCapture && (
        <QueryError error={error} area="los signos vitales" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
      )}
      {!isLoading && !isError && !isPaused && shown.length === 0 && canCapture && (
        <p className="muted">Sin signos vitales registrados todavía.</p>
      )}
      {!isLoading && shown.length > 0 && (
        <div className="patient-vitals-latest">
          {data?.recorded_at && (
            <p className="muted">Última toma: {formatDate(data.recorded_at)}</p>
          )}
          <div className="rd-vitals">
            {shown.map(([label, value]) => (
              <div key={label} className="rd-vital">
                <span className="rd-label">{label}</span>
                <b className="mono">{value}</b>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
