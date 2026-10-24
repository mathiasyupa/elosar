import type { ConsultationType, MedicalRecord, RecordServiceInput } from '@/api/types';
import type { DiagnosisRow, DiagnosisType } from '@/features/patients/workspace/Cie10DiagnosisPicker';
import { rowFromHistory, type PrescriptionRow } from '@/features/patients/workspace/MedicationPicker';
import { EMPTY_SCORE_MAMA, type ScoreMamaCapture } from './ScoreMamaFields';
import { EMPTY_VITALS, type VitalKey } from './vitals';

/** Everything the encounter form holds, as the doctor typed it (strings for
 *  inputs). One shape so a saved visit can be poured back into the screen. */
export interface EncounterFields {
  reason: string;
  diagnosis: string;
  treatment: string;
  recommendations: string;
  alarmSigns: string;
  controlledNo: string;
  followUpDate: string;
  followUpNotes: string;
  currentIllness: string;
  evolution: string;
  physicalExamination: string;
  consultationType: ConsultationType;
  professionalTitle: string;
  structuredExam: Record<string, string>;
  reviewOfSystems: string;
  painScale: string;
  vitals: Record<VitalKey, string>;
  headCircumference: string;
  measurementPosition: 'recumbent_length' | 'standing_height' | '';
  diagnoses: DiagnosisRow[];
  prescriptions: PrescriptionRow[];
  services: RecordServiceInput[];
  scoreMama: ScoreMamaCapture;
}

const text = (value: string | number | null | undefined): string => (value == null ? '' : String(value));

/** A stored visit → the form. Used to resume an atención after a reload or
 *  from the history: before this only the vitals came back, and saving again
 *  would have overwritten the rest with an empty screen. */
export function fieldsFromRecord(record: MedicalRecord): EncounterFields {
  const vital = record.vitals?.[0];
  const vitals: Record<VitalKey, string> = vital
    ? {
        ...EMPTY_VITALS,
        weight_kg: text(vital.weight_kg),
        height_cm: text(vital.height_cm),
        bp_sys: text(vital.blood_pressure_sys),
        bp_dia: text(vital.blood_pressure_dia),
        hr: text(vital.heart_rate),
        temp: text(vital.temperature),
        spo2: text(vital.oxygen_saturation),
        rr: text(vital.respiratory_rate),
      }
    : { ...EMPTY_VITALS };

  return {
    reason: text(record.reason_for_consultation),
    diagnosis: text(record.diagnosis),
    treatment: text(record.treatment),
    recommendations: text(record.recommendations),
    alarmSigns: text(record.alarm_signs),
    controlledNo: text(record.controlled_prescription_no),
    followUpDate: text(record.follow_up_date),
    followUpNotes: text(record.follow_up_notes),
    currentIllness: text(record.current_illness),
    evolution: text(record.evolution),
    physicalExamination: text(record.physical_examination),
    consultationType: (record.consultation_type as ConsultationType | null) ?? 'general',
    professionalTitle: text(record.professional_title),
    structuredExam: { ...(record.structured_exam ?? {}) },
    reviewOfSystems: text(record.review_of_systems),
    painScale: text(record.pain_scale),
    vitals,
    headCircumference: text(vital?.head_circumference_cm),
    measurementPosition: vital?.length_height_measurement_position ?? '',
    diagnoses: (record.diagnoses ?? []).flatMap((d) =>
      d.cie10_code
        ? [{
            cie10_code: d.cie10_code,
            cie10_description: d.cie10_description ?? '',
            type: (d.type as DiagnosisType | null) ?? 'primary',
          }]
        : [],
    ),
    prescriptions: (record.prescriptions ?? []).map(rowFromHistory),
    services: (record.services ?? []).map((s) => ({
      medical_service_id: s.medical_service_id,
      name: s.name,
      category: s.category,
      quantity: Number(s.quantity) || 1,
      unit_price: s.unit_price,
      notes: s.notes,
    })),
    scoreMama: vital
      ? {
          oxygen_therapy: vital.oxygen_therapy ?? null,
          consciousness_level: vital.consciousness_level ?? null,
          urine_output_ml_h: text(vital.urine_output_ml_h),
          urine_output_not_quantifiable: Boolean(vital.urine_output_not_quantifiable),
        }
      : { ...EMPTY_SCORE_MAMA },
  };
}
