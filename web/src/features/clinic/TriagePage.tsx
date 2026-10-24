import { useEffect, useId, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useNavigate, useParams } from 'react-router-dom';
import { isAxiosError } from 'axios';
import {
  fetchClinicQueue,
  saveTriageVitals,
  startTriageDraft,
  type TriageVitals,
} from '@/api/clinic';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { useUiStore } from '@/store/ui';
import { t } from '@/i18n/es';
import { QueryError } from '@/components/ui/QueryError';
import {
  EMPTY_SCORE_MAMA,
  ScoreMamaFields,
  type ScoreMamaCapture,
} from '@/features/encounters/ScoreMamaFields';

const VITAL_FIELDS = [
  ['weight_kg', 'Peso (kg)'],
  ['height_cm', 'Talla (cm)'],
  ['bp_sys', 'PA sistólica'],
  ['bp_dia', 'PA diastólica'],
  ['hr', 'FC (lpm)'],
  ['temp', 'Temp (°C)'],
  ['spo2', 'SpO₂ (%)'],
  ['rr', 'FR (rpm)'],
] as const;

type VitalKey = (typeof VITAL_FIELDS)[number][0];

const EMPTY: Record<VitalKey, string> = {
  weight_kg: '', height_cm: '', bp_sys: '', bp_dia: '', hr: '', temp: '', spo2: '', rr: '',
};

const VITAL_LIMITS: Record<VitalKey, [number, number]> = {
  weight_kg: [0, 500], height_cm: [0, 300], bp_sys: [50, 300], bp_dia: [30, 200],
  hr: [20, 300], temp: [30, 45], spo2: [50, 100], rr: [5, 80],
};

function bmi(weight: string, height: string): string | null {
  const w = Number.parseFloat(weight.replace(',', '.'));
  const h = Number.parseFloat(height.replace(',', '.')) / 100;
  if (!Number.isFinite(w) || !Number.isFinite(h) || h <= 0 || w <= 0) return null;
  return (w / (h * h)).toFixed(1);
}

/**
 * Nurse triage screen: opens (or resumes) the draft for an appointment and
 * captures vitals only. Saving advances the patient to "ready for doctor". The
 * nurse cannot record diagnoses or prescriptions (enforced by the API).
 *
 * Saving used to fire a toast and bounce straight back to the queue. The doctor
 * got a closing screen that says the visit is saved and offers one obvious next
 * step; nursing does the same kind of work and got a redirect. Now it closes the
 * same way — what happened, and who is next.
 */
export function TriagePage() {
  const { appointmentId } = useParams();
  const navigate = useNavigate();
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const apptId = Number(appointmentId);
  // Ata cada rótulo a su control: un <label> suelto no lo anuncia un lector de
  // pantalla y hacerle clic no enfoca el campo.
  const uid = useId();

  const [vitals, setVitals] = useState<Record<VitalKey, string>>(EMPTY);
  const [fieldErrors, setFieldErrors] = useState<Partial<Record<VitalKey, string>>>({});
  // Enfermería toma signos antes de que el médico elija el tipo de consulta, así
  // que aquí el Score MAMÁ siempre va tras el interruptor: no hay un prenatal que
  // pueda encenderlo solo.
  const [scoreMama, setScoreMama] = useState<ScoreMamaCapture>(EMPTY_SCORE_MAMA);
  const [saved, setSaved] = useState(false);

  // Open/resume the draft on mount and pre-fill any vitals already recorded.
  const { data: draft, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['triage-draft', apptId],
    queryFn: () => startTriageDraft(apptId),
    enabled: Number.isFinite(apptId),
  });

  // Quién sigue esperando signos. Se pide sólo al cerrar: mientras se toman los
  // signos no aporta nada, y la cola cambia mientras tanto.
  const { data: queue } = useQuery({
    queryKey: ['clinic-queue', activeWorkspaceId, undefined],
    queryFn: () => fetchClinicQueue(),
    enabled: saved,
  });

  useEffect(() => {
    const v = draft?.vitals?.[0];
    if (!v) return;
    setVitals({
      weight_kg: v.weight_kg ?? '',
      height_cm: v.height_cm ?? '',
      bp_sys: v.blood_pressure_sys != null ? String(v.blood_pressure_sys) : '',
      bp_dia: v.blood_pressure_dia != null ? String(v.blood_pressure_dia) : '',
      hr: v.heart_rate != null ? String(v.heart_rate) : '',
      temp: v.temperature ?? '',
      spo2: v.oxygen_saturation ?? '',
      rr: v.respiratory_rate != null ? String(v.respiratory_rate) : '',
    });
    setScoreMama({
      oxygen_therapy: v.oxygen_therapy ?? null,
      consciousness_level: v.consciousness_level ?? null,
      urine_output_ml_h: v.urine_output_ml_h ?? '',
      urine_output_not_quantifiable: v.urine_output_not_quantifiable ?? false,
    });
  }, [draft]);

  const save = useMutation({
    mutationFn: () => {
      const decimal = (value: string) => value.trim().replace(',', '.');
      const payload: TriageVitals = {
        ...(vitals.weight_kg ? { weight_kg: decimal(vitals.weight_kg) } : {}),
        ...(vitals.height_cm ? { height_cm: decimal(vitals.height_cm) } : {}),
        ...(vitals.bp_sys ? { blood_pressure_sys: Number(vitals.bp_sys) } : {}),
        ...(vitals.bp_dia ? { blood_pressure_dia: Number(vitals.bp_dia) } : {}),
        ...(vitals.hr ? { heart_rate: Number(vitals.hr) } : {}),
        ...(vitals.temp ? { temperature: decimal(vitals.temp) } : {}),
        ...(vitals.spo2 ? { oxygen_saturation: Number(vitals.spo2) } : {}),
        ...(vitals.rr ? { respiratory_rate: Number(vitals.rr) } : {}),
        // El puntaje NO viaja: el servidor lo calcula con estos valores.
        ...(scoreMama.oxygen_therapy ? { oxygen_therapy: scoreMama.oxygen_therapy } : {}),
        ...(scoreMama.consciousness_level ? { consciousness_level: scoreMama.consciousness_level } : {}),
        ...(scoreMama.urine_output_ml_h ? { urine_output_ml_h: Number(scoreMama.urine_output_ml_h) } : {}),
        ...(scoreMama.urine_output_not_quantifiable ? { urine_output_not_quantifiable: true } : {}),
      };
      return saveTriageVitals(draft!.id, payload);
    },
    onSuccess: () => {
      setFieldErrors({});
      qc.invalidateQueries({ queryKey: ['clinic-queue'] });
      // Igual que en la cola: la query del tablero se llama 'dashboard'.
      qc.invalidateQueries({ queryKey: ['dashboard'] });
      setSaved(true);
    },
    onError: (saveError) => {
      const serverErrors = isAxiosError(saveError)
        ? (saveError.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors
        : undefined;
      const names: Record<VitalKey, string> = {
        weight_kg: 'weight_kg',
        height_cm: 'height_cm',
        bp_sys: 'blood_pressure_sys',
        bp_dia: 'blood_pressure_dia',
        hr: 'heart_rate',
        temp: 'temperature',
        spo2: 'oxygen_saturation',
        rr: 'respiratory_rate',
      };
      const next: Partial<Record<VitalKey, string>> = {};
      for (const [key, name] of Object.entries(names) as [VitalKey, string][]) {
        if (serverErrors?.[name]?.[0]) next[key] = serverErrors[name][0];
      }
      setFieldErrors(next);
      toast('No se pudo guardar los signos. Revisa los campos marcados.', 'error');
    },
  });

  const submit = () => {
    const next: Partial<Record<VitalKey, string>> = {};
    for (const key of Object.keys(vitals) as VitalKey[]) {
      const raw = vitals[key].trim().replace(',', '.');
      if (!raw) continue;
      const value = Number(raw);
      const [min, max] = VITAL_LIMITS[key];
      if (!Number.isFinite(value) || value < min || value > max) {
        next[key] = `Ingresa un valor entre ${min} y ${max}.`;
      } else if (['bp_sys', 'bp_dia', 'hr', 'rr'].includes(key) && !Number.isInteger(value)) {
        next[key] = 'Ingresa un número entero.';
      }
    }
    setFieldErrors(next);
    if (Object.keys(next).length === 0) save.mutate();
  };

  if (isLoading) return <Spinner label={t.common.loading} />;
  if (isError || isPaused || !draft)
    return (
      <QueryError error={error} area="los signos vitales" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
    );

  const patientName = draft.patient?.name ?? 'Paciente';

  if (saved) {
    // El siguiente que espera triaje es quien ya llegó y aún no tiene signos
    // (`checked_in`). Un `vitals_done` ya pasó por enfermería.
    const next = (queue?.appointments ?? []).find(
      (a) => a.status === 'checked_in' && a.id !== apptId && a.patient,
    );

    return (
      <div className="close-screen">
        <Card>
          <span className="cs-check" aria-hidden="true">
            <Icon name="check" size={26} strokeWidth={2.4} />
          </span>
          <h2>Signos de {patientName} guardados</h2>
          <p className="muted cs-dx">Queda listo para el médico en la cola.</p>

          <p className="cs-saved">
            <Icon name="check" size={14} />
            Los signos que tomaste llegan cargados a la consulta.
          </p>

          <div className="cs-primary">
            {next?.patient ? (
              <button
                className="btn primary"
                type="button"
                onClick={() => {
                  setSaved(false);
                  setVitals(EMPTY);
                  setScoreMama(EMPTY_SCORE_MAMA);
                  navigate(`/triaje/${next.id}`);
                }}
              >
                <Icon name="steth" size={16} /> Triar a {next.patient.name}
              </button>
            ) : (
              <p className="muted cs-note">No queda nadie esperando signos.</p>
            )}
            <button className="btn" type="button" onClick={() => navigate('/cola')}>
              Volver a la cola
            </button>
          </div>
        </Card>
      </div>
    );
  }

  const computed = bmi(vitals.weight_kg, vitals.height_cm);

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Signos vitales · {patientName}</h1>
          <p>Registra los signos vitales antes de la consulta médica</p>
        </div>
        <button className="btn ghost triage-back" type="button" onClick={() => navigate('/cola')}>
          <Icon name="chevron" size={16} /> Volver a la cola
        </button>
      </div>

      <Card className="enc-section triage-card">
        <h4>Signos vitales</h4>
        <p className="muted triage-hint">
          Todos los campos son opcionales; guarda lo que tengas. El IMC se calcula automáticamente.
        </p>
        <div className="grid triage-grid">
          {VITAL_FIELDS.map(([key, label]) => (
            <div key={key}>
              <label className="field-label" htmlFor={`${uid}-${key}`}>{label}</label>
              <input
                id={`${uid}-${key}`}
                className="field mono"
                inputMode="decimal"
                value={vitals[key]}
                aria-invalid={fieldErrors[key] ? true : undefined}
                aria-describedby={fieldErrors[key] ? `${uid}-${key}-error` : undefined}
                onChange={(e) => setVitals((v) => ({ ...v, [key]: e.target.value }))}
              />
              {fieldErrors[key] && <p className="field-error" id={`${uid}-${key}-error`}>{fieldErrors[key]}</p>}
            </div>
          ))}
        </div>
        <ScoreMamaFields value={scoreMama} onChange={setScoreMama} measured={vitals} />

        {computed && (
          <p className="muted triage-bmi">
            IMC calculado: <strong className="mono">{computed}</strong> kg/m²
          </p>
        )}
        <div className="row triage-actions">
          <button className="btn primary" type="button" disabled={save.isPending} onClick={submit}>
            {save.isPending ? 'Guardando…' : 'Guardar signos'}
          </button>
        </div>
      </Card>
    </>
  );
}
