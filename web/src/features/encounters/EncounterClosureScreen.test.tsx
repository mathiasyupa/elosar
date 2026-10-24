// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/store/auth';
import type { BillableService, Patient, Workspace } from '@/api/types';
import { EncounterClosureScreen } from './EncounterClosureScreen';

vi.mock('@/api/billing', () => ({ fetchBillingDoctors: vi.fn().mockResolvedValue([]) }));
vi.mock('@/api/catalogs', () => ({ fetchMedicalServices: vi.fn().mockResolvedValue([]) }));
vi.mock('@/api/invoices', () => ({ createInvoice: vi.fn() }));
vi.mock('@/api/appointments', () => ({
  createAppointment: vi.fn(),
  updateAppointment: vi.fn(),
  fetchAppointments: vi.fn().mockResolvedValue([]),
}));

const patient = {
  id: 12,
  name: 'María López',
  id_number: '1712345678',
  allergies: [],
} as unknown as Patient;

function actAs(permissions: string[], type: 'personal' | 'clinic' = 'personal') {
  useAuthStore.setState({
    activeWorkspaceId: 1,
    workspaces: [
      {
        id: 1, name: 'Espacio', type, is_clinic: type === 'clinic',
        permissions, roles: [],
      } as unknown as Workspace,
    ],
  });
}

function renderClosure({
  followUpDate = null as string | null,
  services = [] as BillableService[],
} = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <EncounterClosureScreen
          patient={patient}
          diagnosis="Hipertensión controlada"
          completedAppointmentId={7}
          services={services}
          followUpDate={followUpDate}
          onAttendNext={vi.fn()}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, workspaces: [] });
});

describe('EncounterClosureScreen', () => {
  /**
   * The report that caused this redesign: six equal buttons read as six pending
   * steps, and the doctor believed leaving without billing lost the visit.
   */
  it('says the visit is already saved', () => {
    actAs(['records.manage']);
    renderClosure();

    expect(screen.getByText(/Visita de María López terminada/i)).toBeTruthy();
    expect(screen.getByText(/Ya está guardada en la historia/i)).toBeTruthy();
  });

  it('offers exactly one primary way out, and it is finishing', () => {
    actAs(['records.manage', 'billing.manage'], 'clinic');
    renderClosure();

    const primary = document.querySelectorAll('.cs-primary .btn.primary');
    expect(primary).toHaveLength(1);
    expect(primary[0].textContent).toMatch(/Atender al siguiente/i);
  });

  it('sends a solo doctor back to their agenda instead of the queue', () => {
    actAs(['records.manage', 'billing.manage'], 'personal');
    renderClosure();

    expect(screen.getByRole('button', { name: /Volver a la agenda/i })).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Volver a la cola/i })).toBeNull();
  });

  it('opens the just-saved history in the patient workspace tab', () => {
    actAs(['records.manage'], 'personal');
    renderClosure();

    const history = screen.getByRole('link', { name: /Ver la historia de María/i });
    expect(history.getAttribute('href')).toBe('/pacientes/12/historial');
    expect(history.getAttribute('target')).toBe('patient-workspace-12');
  });

  it('keeps billing available but visibly optional', () => {
    actAs(['records.manage', 'billing.manage']);
    renderClosure({ services: [{ medical_service_id: 1, name: 'Consulta', category: 'general', quantity: '1', unit_price: '20.00' }] });

    expect(screen.getByText(/Antes de irte, si quieres/i)).toBeTruthy();
    expect(screen.getByText(/Emitir la factura/i)).toBeTruthy();
    // It says what is already loaded, so nobody re-types it.
    expect(screen.getByText(/1 servicio\(s\) declarado/i)).toBeTruthy();
  });

  it('offers to book the indicated control', () => {
    actAs(['records.manage', 'appointments.manage']);
    renderClosure({ followUpDate: '2026-09-15' });

    expect(screen.getByText(/Agendar el control/i)).toBeTruthy();
    expect(screen.getByText(/queda en Pacientes → Seguimiento/i)).toBeTruthy();
  });

  it('tells a clinic doctor who cannot bill where the visit went', () => {
    actAs(['records.manage', 'billing.view'], 'clinic');
    renderClosure();

    expect(screen.getByText(/pendiente de facturar en caja/i)).toBeTruthy();
    expect(screen.queryByText(/Emitir la factura/i)).toBeNull();
  });

  it('shows no optional block at all when there is nothing optional to do', () => {
    // A nurse: no billing, no agenda. Finishing is the only thing left.
    actAs(['vitals.create']);
    renderClosure();

    expect(screen.queryByText(/Antes de irte/i)).toBeNull();
  });
});
