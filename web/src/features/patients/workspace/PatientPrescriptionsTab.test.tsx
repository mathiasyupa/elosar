// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchPatientRecords } from '@/api/patients';
import { fetchRecord } from '@/api/records';
import { useAuthStore } from '@/store/auth';
import type { MedicalRecord, Paginated, Patient, RecordPrescription, RecordStatus } from '@/api/types';
import type { PatientWorkspaceContext } from './context';
import { PatientPrescriptionsTab } from './PatientPrescriptionsTab';

vi.mock('@/api/patients', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/patients')>()),
  fetchPatientRecords: vi.fn(),
}));
vi.mock('@/api/records', () => ({
  fetchRecord: vi.fn(),
  downloadPrescriptionPdf: vi.fn(),
}));

const patient: Patient = {
  id: 12,
  name: 'María López',
  id_number: '1712345678',
  birth_date: '1988-03-15',
  age: 38,
  gender: 'female',
  phone: '0991234567',
  email: 'maria@example.com',
  city: null,
  address: 'Av. Amazonas N34-120',
  allergies: [],
  family_background: [],
};

const workspaceContext: PatientWorkspaceContext = { patient, patientId: 12 };
vi.mock('./context', () => ({
  usePatientWorkspace: () => workspaceContext,
}));

function prescription(over: Partial<RecordPrescription> & { id: number }): RecordPrescription {
  return {
    medication_name: null,
    generic_name: null,
    concentration: null,
    dosage: null,
    route: null,
    frequency: null,
    duration_days: null,
    quantity: null,
    instructions: null,
    is_controlled: false,
    ...over,
  };
}

function makeRecord(over: Partial<MedicalRecord> & { id: number; status: RecordStatus }): MedicalRecord {
  return {
    appointment_id: null,
    patient_id: 12,
    created_at: '2026-09-21T14:00:00Z',
    reason_for_consultation: null,
    current_illness: null,
    physical_examination: null,
    review_of_systems: null,
    pain_scale: null,
    diagnosis: null,
    treatment: null,
    follow_up_date: null,
    follow_up_notes: null,
    ...over,
  };
}

function page(records: MedicalRecord[]): Paginated<MedicalRecord> {
  return {
    data: records,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: null, last_page: 1, per_page: 20, to: null, total: records.length },
  };
}

function renderTab() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <PatientPrescriptionsTab />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  useAuthStore.setState({ activeWorkspaceId: 1 });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, workspaces: [] });
});

describe('PatientPrescriptionsTab', () => {
  // The read path for what the form still captures. The table used to list
  // dosage, frequency and duration — the three fields that stopped being
  // captured on 2026-09-21 — and never the two it kept, so every new
  // prescription would have read as a row of dashes.
  it('shows the quantity and the instructions of a three-field prescription', async () => {
    vi.mocked(fetchPatientRecords).mockResolvedValue(page([makeRecord({ id: 91, status: 'completed' })]));
    vi.mocked(fetchRecord).mockResolvedValue(
      makeRecord({
        id: 91,
        status: 'completed',
        prescriptions: [
          prescription({
            id: 1,
            medication_name: 'Betametasona crema',
            quantity: 1,
            instructions: 'Aplicar una capa fina dos veces al día.',
          }),
        ],
      }) as never,
    );

    renderTab();

    expect(await screen.findByText('Betametasona crema')).toBeTruthy();
    expect(screen.getByText('Aplicar una capa fina dos veces al día.')).toBeTruthy();
    expect(screen.getByText('1')).toBeTruthy();
    // The columns it kept are the ones the doctor fills in.
    expect(screen.getByRole('columnheader', { name: 'Cantidad' })).toBeTruthy();
    expect(screen.getByRole('columnheader', { name: 'Indicaciones' })).toBeTruthy();
  });

  // Records written before the change keep their structured values and must
  // stay readable — losing them would rewrite history, not simplify a form.
  it('keeps an older prescription readable, with its posology as a second line', async () => {
    vi.mocked(fetchPatientRecords).mockResolvedValue(page([makeRecord({ id: 92, status: 'completed' })]));
    vi.mocked(fetchRecord).mockResolvedValue(
      makeRecord({
        id: 92,
        status: 'completed',
        prescriptions: [
          prescription({
            id: 2,
            medication_name: 'Paracetamol',
            concentration: '500mg',
            dosage: '1 tableta',
            frequency: 'cada 8 horas',
            duration_days: 5,
            quantity: 15,
            instructions: 'Tomar con alimentos.',
          }),
        ],
      }) as never,
    );

    renderTab();

    expect(await screen.findByText('Paracetamol')).toBeTruthy();
    expect(screen.getByText('1 tableta · cada 8 horas · 5 días')).toBeTruthy();
    expect(screen.getByText('15')).toBeTruthy();
    expect(screen.getByText('Tomar con alimentos.')).toBeTruthy();
  });

  // A prescription with neither posology nor instructions must not grow an
  // empty secondary line under the name.
  it('does not print an empty posology line when there is none', async () => {
    vi.mocked(fetchPatientRecords).mockResolvedValue(page([makeRecord({ id: 93, status: 'completed' })]));
    vi.mocked(fetchRecord).mockResolvedValue(
      makeRecord({
        id: 93,
        status: 'completed',
        prescriptions: [prescription({ id: 3, medication_name: 'Suero fisiológico', quantity: 2 })],
      }) as never,
    );

    renderTab();

    const name = await screen.findByText('Suero fisiológico');
    await waitFor(() => expect(name.closest('td')?.querySelectorAll('div.muted').length).toBe(0));
  });

  // A forgotten medication is fixed on the same visit, from where the doctor
  // sees the receta; reception can read it but never rewrite it.
  it('offers to edit the receta only to whoever can write clinical records', async () => {
    vi.mocked(fetchPatientRecords).mockResolvedValue(page([makeRecord({ id: 94, status: 'completed' })]));
    vi.mocked(fetchRecord).mockResolvedValue(
      makeRecord({
        id: 94,
        status: 'completed',
        prescriptions: [prescription({ id: 4, medication_name: 'Ibuprofeno', quantity: 9 })],
      }) as never,
    );

    useAuthStore.setState({ workspaces: [{ id: 1, permissions: ['records.view', 'records.manage'] }] as never });
    renderTab();
    const edit = await screen.findByRole('link', { name: /Editar receta/ });
    expect(edit.getAttribute('href')).toBe('/pacientes/12/atencion?record=94&tab=rx');

    cleanup();
    useAuthStore.setState({ workspaces: [{ id: 1, permissions: ['records.view'] }] as never });
    renderTab();
    await screen.findByText('Ibuprofeno');
    expect(screen.queryByRole('link', { name: /Editar receta/ })).toBeNull();
  });
});
