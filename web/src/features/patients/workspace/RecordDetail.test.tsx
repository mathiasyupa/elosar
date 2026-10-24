// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { MedicalRecord } from '@/api/types';
import { RecordDetail } from './RecordDetail';

const record = {
  id: 55,
  patient_id: 12,
  appointment_id: null,
  status: 'completed',
  created_at: '2026-08-16T15:00:00',
  reason_for_consultation: 'Dolor abdominal',
  consultation_type: 'general',
  structured_exam_template_version: 1,
  structured_exam: {
    cabeza: 'Normocéfalo, sin lesiones',
    abdomen: 'Blando, depresible, doloroso en epigastrio',
  },
  physical_examination: 'Paciente en buen estado general.',
  current_illness: 'Cuadro de 3 días',
  review_of_systems: null,
  pain_scale: 6,
  diagnosis: 'Gastritis aguda',
  treatment: 'Omeprazol 20mg',
  recommendations: 'Dieta blanda',
  alarm_signs: 'Vómito con sangre',
  follow_up_date: '2026-08-30',
  follow_up_notes: 'Reevaluar dolor',
  vitals: [{ id: 1, weight_kg: '70', height_cm: '175', bmi: '22.9', heart_rate: 72 }],
  services: [
    { id: 3, medical_service_id: 7, name: 'Biometría hemática', category: 'laboratory', quantity: '1.00', unit_price: '17.50', line_total: '17.50', notes: null },
  ],
} as unknown as MedicalRecord;

afterEach(cleanup);

describe('RecordDetail', () => {
  // The reason this component exists: every one of these was written by the
  // doctor and then unreadable anywhere in the app.
  it('reads back the structured exploration under its segment labels', () => {
    render(<RecordDetail record={record} />);

    expect(screen.getByText('Cabeza')).toBeTruthy();
    expect(screen.getByText('Normocéfalo, sin lesiones')).toBeTruthy();
    expect(screen.getByText('Abdomen')).toBeTruthy();
    expect(screen.getByText(/doloroso en epigastrio/)).toBeTruthy();
  });

  it('shows the plan, including the alarm signs', () => {
    render(<RecordDetail record={record} />);

    expect(screen.getByText('Omeprazol 20mg')).toBeTruthy();
    expect(screen.getByText('Dieta blanda')).toBeTruthy();
    expect(screen.getByText('Vómito con sangre')).toBeTruthy();
  });

  it('lists the services that were declared as performed', () => {
    render(<RecordDetail record={record} />);

    expect(screen.getByText(/Biometría hemática/)).toBeTruthy();
    expect(screen.getByText(/Laboratorio/)).toBeTruthy();
  });

  it('shows the vitals it has and stays silent about the rest', () => {
    render(<RecordDetail record={record} />);

    expect(screen.getByText('22.9 kg/m²')).toBeTruthy();
    expect(screen.getByText('72 lpm')).toBeTruthy();
    // No blood pressure was taken — no empty row for it.
    expect(screen.queryByText('PA')).toBeNull();
  });

  it('reads back the Score MAMÁ inputs and server result from vitals', () => {
    render(
      <RecordDetail
        record={{
          ...record,
          vitals: [{
            id: 1,
            oxygen_therapy: 'nasal_cannula',
            consciousness_level: 'responds_to_voice',
            urine_output_not_quantifiable: true,
            score_mama_total: 5,
            score_mama_level: 'emergency',
            score_mama_protocol: 'MSP-2025',
          }],
        } as unknown as MedicalRecord}
      />,
    );

    expect(screen.getByText('Catéter nasal')).toBeTruthy();
    expect(screen.getByText('Responde a la voz')).toBeTruthy();
    expect(screen.getByText('No cuantificable (sin sonda / ambulatoria)')).toBeTruthy();
    expect(screen.getByText('5')).toBeTruthy();
    expect(screen.getByText(/activar clave obstétrica/i)).toBeTruthy();
    expect(screen.getByText('MSP-2025')).toBeTruthy();
  });

  it('renders an unknown exam key rather than dropping the note', () => {
    render(
      <RecordDetail
        record={{ ...record, structured_exam: { clave_retirada: 'Hallazgo importante' } } as unknown as MedicalRecord}
      />,
    );

    // A clinical note must not disappear because a template changed later.
    expect(screen.getByText('Hallazgo importante')).toBeTruthy();
    expect(screen.getByText('clave_retirada')).toBeTruthy();
  });

  it('never borrows a label from another type when reading a versioned record', () => {
    render(
      <RecordDetail
        record={{ ...record, structured_exam: { fum: '2026-05-01' } } as unknown as MedicalRecord}
      />,
    );

    // FUM belongs to the gynecological v1 snapshot, not to a general record.
    expect(screen.getByText('fum')).toBeTruthy();
    expect(screen.queryByText('FUM (última menstruación)')).toBeNull();
  });

  it('omits sections the consultation never filled in', () => {
    render(
      <RecordDetail
        record={{ id: 1, structured_exam: null, vitals: [], services: [] } as unknown as MedicalRecord}
      />,
    );

    expect(screen.queryByText('Plan')).toBeNull();
    expect(screen.queryByText('Exploración física')).toBeNull();
    expect(screen.queryByText('Servicios realizados')).toBeNull();
  });
});
