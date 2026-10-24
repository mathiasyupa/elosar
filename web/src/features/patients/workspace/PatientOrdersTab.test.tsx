// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { StudyOrder } from '@/api/types';

/**
 * An order belongs to the patient; the consultation is optional.
 *
 * The rule worth pinning is not the wiring but the trade it makes: dropping the
 * consultation is allowed, and the moment you do, the clinical indication stops
 * being optional — it becomes the only thing on the order that says why the
 * study was requested, and it is what the PDF prints.
 */

const fetchPatientOrders = vi.fn();
const createPatientOrder = vi.fn();

vi.mock('@/api/studyOrders', () => ({
  fetchPatientOrders: (...args: unknown[]) => fetchPatientOrders(...args),
  createPatientOrder: (...args: unknown[]) => createPatientOrder(...args),
  deleteStudyOrder: vi.fn(),
  downloadStudyOrderPdf: vi.fn().mockRejectedValue(new Error('sin pdf en test')),
  updateStudyOrderResult: vi.fn(),
  fetchStudies: vi.fn().mockResolvedValue([]),
  createStudy: vi.fn(),
  deleteStudy: vi.fn(),
}));

const fetchPatientRecords = vi.fn();
vi.mock('@/api/patients', () => ({
  fetchPatientRecords: (...args: unknown[]) => fetchPatientRecords(...args),
}));

vi.mock('./context', () => ({
  usePatientWorkspace: () => ({ patientId: 36, patient: { id: 36, name: 'Diego Salazar Once' } }),
}));

vi.mock('@/lib/capabilities', () => ({
  useWorkspaceCapabilities: () => ({ can: () => true }),
}));

vi.mock('@/store/auth', () => ({
  useAuthStore: (selector: (s: unknown) => unknown) => selector({ activeWorkspaceId: 1 }),
}));

const toast = vi.fn();
vi.mock('@/store/ui', () => ({
  useUiStore: (selector: (s: unknown) => unknown) => selector({ toast }),
}));

vi.mock('@/components/LetterheadNotice', () => ({ LetterheadNotice: () => null }));

// The catalogue picker is a screen of its own; here it only needs to let a
// study be added so the "Generar orden" button can become reachable.
vi.mock('./StudyOrderBuilder', () => ({
  StudyOrderBuilder: ({ onChange }: { onChange: (items: unknown[]) => void }) => (
    <button type="button" onClick={() => onChange([{ name: 'Creatinina', category: 'Bioquímico' }])}>
      añadir examen de prueba
    </button>
  ),
}));

import { PatientOrdersTab } from './PatientOrdersTab';

function renderTab() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={client}>
      <PatientOrdersTab />
    </QueryClientProvider>,
  );
}

const orderFromVisit = {
  id: 1,
  medical_record_id: 39,
  medical_record: { id: 39, created_at: '2026-08-24T10:00:00-05:00', reason_for_consultation: 'Control de hipertensión' },
  patient_id: 36,
  type: 'laboratory',
  type_label: 'Laboratorio',
  priority: 'routine',
  clinical_info: null,
  notes: null,
  to_be_performed_at: null,
  created_at: '2026-08-24T10:00:00-05:00',
  result_status: 'pending',
  result_status_label: 'Pendiente',
  result_received_at: null,
  result_notes: null,
  items: [{ id: 1, study_id: null, name: 'Creatinina', category: 'Bioquímico', notes: null }],
} as unknown as StudyOrder;

const orderOnItsOwn = {
  ...orderFromVisit,
  id: 2,
  medical_record_id: null,
  medical_record: null,
  clinical_info: 'Control anual solicitado por teléfono.',
} as unknown as StudyOrder;

beforeEach(() => {
  vi.clearAllMocks();
  fetchPatientRecords.mockResolvedValue({
    data: [{ id: 39, created_at: '2026-08-24T10:00:00-05:00', reason_for_consultation: 'Control de hipertensión', diagnosis: 'Hipertensión esencial' }],
  });
  fetchPatientOrders.mockResolvedValue([orderFromVisit, orderOnItsOwn]);
  createPatientOrder.mockResolvedValue({ ...orderFromVisit, id: 9 });
});

afterEach(cleanup);

describe('PatientOrdersTab', () => {
  it('lista todas las órdenes del paciente, tengan consulta o no', async () => {
    renderTab();

    // Se piden por paciente, no por consulta: antes una orden sólo era
    // alcanzable a través de la visita que la produjo.
    await waitFor(() => expect(fetchPatientOrders).toHaveBeenCalledWith(36));

    expect(await screen.findByText('Control de hipertensión')).toBeTruthy();
    expect(screen.getByText('Sin consulta')).toBeTruthy();
  });

  it('deja emitir sin consulta, pero entonces exige la indicación clínica', async () => {
    const user = userEvent.setup();
    renderTab();

    await screen.findByLabelText(/Consulta asociada/);
    await user.click(screen.getByRole('button', { name: /añadir examen de prueba/ }));

    const generar = screen.getByRole('button', { name: /Generar orden/ });
    // Con la consulta puesta por defecto, el diagnóstico viene de ella.
    expect((generar as HTMLButtonElement).disabled).toBe(false);

    await user.selectOptions(screen.getByLabelText(/Consulta asociada/), '');

    await waitFor(() =>
      expect((screen.getByRole('button', { name: /Generar orden/ }) as HTMLButtonElement).disabled).toBe(true),
    );

    const indicacion = screen.getByLabelText(/indicación clínica/i);
    await user.type(indicacion, 'Control anual solicitado por teléfono.');

    await waitFor(() =>
      expect((screen.getByRole('button', { name: /Generar orden/ }) as HTMLButtonElement).disabled).toBe(false),
    );

    await user.click(screen.getByRole('button', { name: /Generar orden/ }));

    await waitFor(() => expect(createPatientOrder).toHaveBeenCalled());
    expect(createPatientOrder.mock.calls[0][0]).toBe(36);
    expect(createPatientOrder.mock.calls[0][1]).toMatchObject({
      medical_record_id: null,
      clinical_info: 'Control anual solicitado por teléfono.',
    });
  });
});
