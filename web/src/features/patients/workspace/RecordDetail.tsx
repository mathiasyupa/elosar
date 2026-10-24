import { examLabelForRecord } from './clinicalTemplates';
import { formatDate, formatMoney } from '@/i18n/es';
import type { MedicalRecord } from '@/api/types';
import { SCORE_MAMA_LEVEL_LABELS, type ConsciousnessLevel, type OxygenTherapy } from '@/features/encounters/scoreMama';

/** Arancel categories (English in the schema, Spanish on screen). */
const SERVICE_CATEGORY_LABELS: Record<string, string> = {
  general: 'General',
  laboratory: 'Laboratorio',
  imaging: 'Imagenología',
  procedure: 'Procedimiento',
  dental: 'Odontología',
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

const GROWTH_INDICATOR_LABELS: Record<string, string> = {
  weight_for_age: 'Peso para la edad',
  length_height_for_age: 'Longitud/estatura para la edad',
  head_circumference_for_age: 'Perímetro cefálico para la edad',
};

const GROWTH_REASON_LABELS: Record<string, string> = {
  birth_date_missing: 'Falta fecha de nacimiento', sex_not_supported: 'El sexo registrado no permite usar este estándar',
  age_out_of_range: 'Edad fuera del rango OMS 0–5 años', measurement_missing: 'Falta una medición',
  measurement_position_missing: 'Falta cómo se midió la longitud/talla', measurement_outside_standard: 'Medición fuera del rango de la tabla OMS',
};

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="rd-section">
      <h5>{title}</h5>
      {children}
    </section>
  );
}

function Field({ label, value }: { label: string; value: string | null | undefined }) {
  if (!value?.trim()) return null;
  return (
    <div className="rd-field">
      <span className="rd-label">{label}</span>
      <p>{value}</p>
    </div>
  );
}

/**
 * Everything a closed consultation recorded, read-only.
 *
 * This did not exist: the timeline showed date, reason and CIE-10 codes, and
 * the rest of the record — exam, plan, recommendations, alarm signs, vitals —
 * was written and never shown again anywhere in the app or in any PDF. The
 * structured exploration was the most visible casualty, since a doctor filling
 * in six body segments could not read one of them back.
 */
export function RecordDetail({ record }: { record: MedicalRecord }) {
  const vitals = record.vitals?.[0];
  const exam = record.structured_exam ?? {};
  const examEntries = Object.entries(exam).filter(([, v]) => typeof v === 'string' && v.trim());
  const services = record.services ?? [];
  const growth = record.growth_assessments?.[0];

  const vitalPairs: [string, string | number | null | undefined][] = vitals
    ? [
        ['Peso', vitals.weight_kg ? `${vitals.weight_kg} kg` : null],
        ['Talla', vitals.height_cm ? `${vitals.height_cm} cm` : null],
        ['Perímetro cefálico', vitals.head_circumference_cm ? `${vitals.head_circumference_cm} cm` : null],
        ['IMC', vitals.bmi ? `${vitals.bmi} kg/m²` : null],
        ['PA', vitals.blood_pressure_sys && vitals.blood_pressure_dia
          ? `${vitals.blood_pressure_sys}/${vitals.blood_pressure_dia} mmHg` : null],
        ['FC', vitals.heart_rate ? `${vitals.heart_rate} lpm` : null],
        ['Temp.', vitals.temperature ? `${vitals.temperature} °C` : null],
        ['SpO₂', vitals.oxygen_saturation ? `${vitals.oxygen_saturation} %` : null],
        ['FR', vitals.respiratory_rate ? `${vitals.respiratory_rate} rpm` : null],
        ['Oxigenoterapia', vitals.oxygen_therapy ? OXYGEN_THERAPY_LABELS[vitals.oxygen_therapy] : null],
        ['Nivel de consciencia', vitals.consciousness_level ? CONSCIOUSNESS_LABELS[vitals.consciousness_level] : null],
        ['Gasto urinario', vitals.urine_output_not_quantifiable
          ? 'No cuantificable (sin sonda / ambulatoria)'
          : vitals.urine_output_ml_h ? `${vitals.urine_output_ml_h} mL/h` : null],
        ['Score MAMÁ', vitals.score_mama_total != null ? String(vitals.score_mama_total) : null],
        ['Nivel Score MAMÁ', vitals.score_mama_level ? SCORE_MAMA_LEVEL_LABELS[vitals.score_mama_level] : null],
        ['Protocolo Score MAMÁ', vitals.score_mama_protocol ?? null],
      ]
    : [];
  const shownVitals = vitalPairs.filter(([, v]) => v != null && v !== '');

  return (
    <div className="record-detail">
      {shownVitals.length > 0 && (
        <Section title="Signos vitales">
          <div className="rd-vitals">
            {shownVitals.map(([label, value]) => (
              <div key={label} className="rd-vital">
                <span className="rd-label">{label}</span>
                <b className="mono">{value}</b>
              </div>
            ))}
          </div>
        </Section>
      )}

      {growth && (
        <Section title="Evaluación de crecimiento">
          {growth.indicators.map((indicator) => (
            <Field
              key={indicator.code}
              label={GROWTH_INDICATOR_LABELS[indicator.code] ?? indicator.code}
              value={indicator.status === 'calculated'
                ? `Z ${indicator.z_score ?? '—'} · percentil ${indicator.percentile ?? 'no disponible'}`
                : GROWTH_REASON_LABELS[indicator.reason_code ?? growth.reason_code ?? ''] ?? 'No calculable'}
            />
          ))}
          <Field label="Referencia" value={`${growth.standard.source} · ${growth.standard.version}`} />
          <p className="muted">Resultado de referencia OMS; no establece diagnóstico ni conducta.</p>
        </Section>
      )}

      {(record.evolution || record.current_illness || record.review_of_systems || record.pain_scale != null) && (
        <Section title="Anamnesis">
          <Field label="Enfermedad actual" value={record.current_illness} />
          <Field label="Evolución" value={record.evolution} />
          <Field label="Revisión por sistemas" value={record.review_of_systems} />
          {record.pain_scale != null && (
            <Field label="Escala de dolor" value={`${record.pain_scale} / 10`} />
          )}
        </Section>
      )}

      {(examEntries.length > 0 || record.physical_examination) && (
        <Section title="Exploración física">
          {examEntries.length > 0 && (
            <div className="rd-exam">
              {examEntries.map(([key, value]) => (
                <div key={key} className="rd-field">
                  <span className="rd-label">
                    {examLabelForRecord(record.consultation_type, record.structured_exam_template_version, key)}
                  </span>
                  <p>{value as string}</p>
                </div>
              ))}
            </div>
          )}
          <Field label="Notas" value={record.physical_examination} />
        </Section>
      )}

      {(record.treatment || record.recommendations || record.alarm_signs) && (
        <Section title="Plan">
          <Field label="Plan terapéutico" value={record.treatment} />
          <Field label="Recomendaciones" value={record.recommendations} />
          <Field label="Signos de alarma" value={record.alarm_signs} />
        </Section>
      )}

      {services.length > 0 && (
        <Section title="Servicios realizados">
          <ul className="rd-services">
            {services.map((s) => (
              <li key={s.id}>
                <span>
                  {s.name}
                  {s.category && (
                    <span className="muted">{` · ${SERVICE_CATEGORY_LABELS[s.category] ?? s.category}`}</span>
                  )}
                  {Number(s.quantity) !== 1 && <span className="muted">{` · ×${s.quantity}`}</span>}
                </span>
                {/* Null price = declared by a doctor who may not set one; the
                    cash desk priced it on the invoice, not here. */}
                {s.line_total && <b className="mono">{formatMoney(s.line_total)}</b>}
              </li>
            ))}
          </ul>
        </Section>
      )}

      {record.follow_up_date && (
        <Section title="Próximo control">
          <Field
            label={formatDate(record.follow_up_date)}
            value={record.follow_up_notes ?? 'Sin motivo indicado'}
          />
        </Section>
      )}
    </div>
  );
}
