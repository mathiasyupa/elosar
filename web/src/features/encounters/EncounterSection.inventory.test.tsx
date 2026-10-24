// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchBaselineVitals, fetchPatientRecords } from '@/api/patients';
import { createRecord } from '@/api/records';
import { fetchPatientCertificates } from '@/api/certificates';
import { useAuthStore } from '@/store/auth';
import { useEncounterGuardStore } from '@/store/encounterGuard';
import { useUiStore } from '@/store/ui';
import type { ClinicalModule, MedicalRecord, Paginated, Patient, Workspace } from '@/api/types';
import type { PatientWorkspaceContext } from '@/features/patients/workspace/context';
import { EncounterSection } from './EncounterSection';

vi.mock('@/api/patients', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/patients')>()),
  fetchBaselineVitals: vi.fn(),
  fetchPatientRecords: vi.fn(),
}));
vi.mock('@/api/records', () => ({
  createRecord: vi.fn(),
  completeRecord: vi.fn(),
  fetchRecord: vi.fn(),
  downloadPrescriptionPdf: vi.fn(),
}));
vi.mock('@/api/clinic', () => ({ fetchNextPatient: vi.fn() }));
vi.mock('@/api/certificates', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/certificates')>()),
  fetchPatientCertificates: vi.fn(),
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

let workspaceContext: PatientWorkspaceContext = { patient, patientId: 12 };
vi.mock('@/features/patients/workspace/context', () => ({
  usePatientWorkspace: () => workspaceContext,
}));

function page(records: MedicalRecord[]): Paginated<MedicalRecord> {
  return {
    data: records,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: null, last_page: 1, per_page: 20, to: null, total: records.length },
  };
}

function actAs(
  permissions: string[],
  type: 'personal' | 'clinic' = 'personal',
  clinicalModules: ClinicalModule[] = [],
) {
  useAuthStore.setState({
    activeWorkspaceId: 1,
    doctor: {
      id: 1,
      name: 'Dra. Prueba',
      id_number: null,
      email: null,
      phone: null,
      specialty: 'Medicina General',
      clinical_modules: clinicalModules,
    },
    workspaces: [
      {
        id: 1, name: type === 'clinic' ? 'Clínica' : 'Consultorio',
        type, is_clinic: type === 'clinic', permissions, roles: [],
      } as unknown as Workspace,
    ],
  });
}

/** Mounted directly, without PatientWorkspaceLayout or PatientRail around it — the
 *  patient comes from `workspaceContext` above (mocked), matching how the real layout
 *  hands it down via Outlet context. `/pacientes/:patientId/atencion` still needs a
 *  route so `useSearchParams`/`?draft=`/`?appointment=` resolve the same way they do
 *  in production. */
function renderEncounter(entry = '/pacientes/12/atencion') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route path="/pacientes/:patientId/atencion" element={<EncounterSection />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  workspaceContext = { patient, patientId: 12 };
  // jsdom does not implement createObjectURL; `downloadBlob` calls it to save the PDF.
  if (!URL.createObjectURL) {
    Object.defineProperty(URL, 'createObjectURL', {
      value: vi.fn(() => 'blob:mock'),
      writable: true,
      configurable: true,
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
      value: vi.fn(),
      writable: true,
      configurable: true,
    });
  }
  vi.mocked(fetchBaselineVitals).mockResolvedValue(null as never);
  vi.mocked(fetchPatientRecords).mockResolvedValue(page([]));
  vi.mocked(fetchPatientCertificates).mockResolvedValue(page([]) as never);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, workspaces: [] });
  useEncounterGuardStore.setState({ hasUnsavedWork: false });
  useUiStore.setState({ toasts: [] });
  vi.restoreAllMocks();
});

/**
 * Inventory of the encounter's capabilities. These tests were written against
 * the old step layout, before moving its UI into three columns. They use roles
 * and visible text because the structure itself is what changes.
 */
describe('Encounter capability inventory', () => {
  it('offers the working sections and the certificate action when permitted', async () => {
    actAs(['records.manage', 'records.view']);
    renderEncounter();
    for (const section of [
      /Evaluación clínica|Exploración/i,
      /Diagnóstico/i,
      /Prescripción|Receta/i,
      /Exámenes/i,
      /Servicios/i,
      /Certificado/i,
    ]) {
      expect(await screen.findByRole('tab', { name: section })).toBeTruthy();
    }
  });

  it('opens certificate issuance inside the encounter without leaving typed work', async () => {
    const user = userEvent.setup();
    actAs(['records.manage', 'records.view']);
    renderEncounter();
    await user.type(await screen.findByPlaceholderText(/dolor abdominal de 3 días/i), 'Control');
    await user.click(screen.getByRole('tab', { name: 'Certificado' }));
    await user.click(await screen.findByRole('button', { name: 'Emitir certificado' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(screen.getByText('Emitir certificado médico')).toBeTruthy();
    expect((screen.getByPlaceholderText(/dolor abdominal de 3 días/i) as HTMLTextAreaElement).value).toBe('Control');
  });

  it('hides certificate issuance without records.view', async () => {
    actAs(['records.manage']);
    renderEncounter();
    expect(await screen.findByRole('tab', { name: 'Exploración' })).toBeTruthy();
    expect(screen.queryByRole('tab', { name: 'Certificado' })).toBeNull();
  });

  it('keeps the required reason editable', async () => {
    renderEncounter();
    expect(await screen.findByPlaceholderText(/dolor abdominal de 3 días/i)).toBeTruthy();
  });

  it('keeps vital entry and the derived BMI', async () => {
    const user = userEvent.setup();
    renderEncounter();
    await user.type(await screen.findByLabelText('Peso (kg)'), '70');
    await user.type(screen.getByLabelText('Talla (cm)'), '175');
    expect(screen.getByText(/22\.9/)).toBeTruthy();
    expect(screen.getByText(/Normal/)).toBeTruthy();
  });

  it('keeps the segmented examination fields', async () => {
    const user = userEvent.setup();
    renderEncounter();
    await user.click(await screen.findByRole('tab', { name: /Evaluación clínica|Exploración/i }));
    expect(screen.getByRole('heading', { name: /Exploración física por segmentos/i })).toBeTruthy();
    expect(screen.getByLabelText('Abdomen')).toBeTruthy();
  });

  it('keeps patient history accessible', async () => {
    const user = userEvent.setup();
    renderEncounter();
    await user.click(await screen.findByRole('button', { name: /Antecedentes del paciente/i }));
    expect(screen.getByText('Antecedentes personales')).toBeTruthy();
  });

  it('keeps the CIE-10 search and diagnostic impression', async () => {
    const user = userEvent.setup();
    renderEncounter();
    await user.click(await screen.findByRole('tab', { name: /Diagnóstico/i }));
    expect(screen.getByPlaceholderText('Buscar código o descripción…')).toBeTruthy();
    expect(screen.getByPlaceholderText(/Describe la impresión clínica/i)).toBeTruthy();
  });

  it('keeps the three-field prescription and PDF action', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: null } as never);
    renderEncounter();
    await user.type(await screen.findByPlaceholderText(/dolor abdominal de 3 días/i), 'Cefalea');
    await user.click(screen.getByRole('tab', { name: /Prescripción|Receta/i }));
    await user.click(screen.getByRole('button', { name: /Añadir medicamento/i }));
    expect(screen.getByLabelText('Nombre *')).toBeTruthy();
    expect(screen.getByLabelText('Cantidad a dispensar *')).toBeTruthy();
    expect(screen.getByLabelText('Indicaciones al paciente')).toBeTruthy();
    expect(screen.getAllByRole('button', { name: /Imprimir receta/i }).length).toBeGreaterThan(0);
  });

  it('hides gyneco-obstetric consultation types without the clinical module', async () => {
    actAs([], 'personal', []);
    renderEncounter();
    const select = await screen.findByLabelText('Tipo de consulta');
    const options = [...select.querySelectorAll('option')].map((option) => option.value);
    expect(options).not.toContain('gynecological');
    expect(options).not.toContain('prenatal');
  });

  it('offers gyneco-obstetric consultation types with the clinical module', async () => {
    actAs([], 'personal', ['gyneco_obstetrics']);
    renderEncounter();
    const select = await screen.findByLabelText('Tipo de consulta');
    const options = [...select.querySelectorAll('option')].map((option) => option.value);
    expect(options).toContain('gynecological');
    expect(options).toContain('prenatal');
  });

  it('shows the pregnancy chip only with the gyneco-obstetric module', async () => {
    const pregnant = {
      ...patient,
      active_pregnancy: { status: 'active', gestational_age: { is_valid: false } },
    } as unknown as Patient;

    workspaceContext = { patient: pregnant, patientId: 12 };
    actAs([], 'personal', ['gyneco_obstetrics']);
    renderEncounter();
    expect(await screen.findByText('Embarazo sin datar')).toBeTruthy();
    cleanup();

    workspaceContext = { patient: pregnant, patientId: 12 };
    actAs([], 'personal', []);
    renderEncounter();
    await screen.findByLabelText('Tipo de consulta');
    expect(screen.queryByText('Embarazo sin datar')).toBeNull();
  });

  it('marks the diagnosis section once it has content', async () => {
    const user = userEvent.setup();
    renderEncounter();
    await user.click(await screen.findByRole('tab', { name: /Diagnóstico/i }));
    expect(screen.getByRole('tab', { name: 'Diagnóstico' })).toBeTruthy();
    await user.type(screen.getByPlaceholderText(/Describe la impresión clínica/i), 'Cefalea tensional');
    expect(screen.getByRole('tab', { name: /Diagnóstico\s*\(completo\)/ })).toBeTruthy();
  });

  it('keeps a live summary with the receta, finishing and a new visit', async () => {
    actAs(['records.manage']);
    renderEncounter();
    const summary = await screen.findByRole('complementary', { name: 'Resumen de la atención' });
    for (const name of [/Imprimir receta/, /Terminar atención/, /Empezar otra atención/]) {
      expect(within(summary).getByRole('button', { name })).toBeTruthy();
    }
    expect(within(summary).getByText('Sin diagnóstico todavía.')).toBeTruthy();
  });
});


