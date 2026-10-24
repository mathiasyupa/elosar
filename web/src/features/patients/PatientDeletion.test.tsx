// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { deletePatient, fetchPatient, fetchPatients, previewPatientDeletion } from '@/api/patients';
import type { Paginated, Patient, Permission, Workspace } from '@/api/types';
import { PageActionProvider } from '@/components/shell/PageActionContext';
import { useAuthStore } from '@/store/auth';
import { PatientsPage } from './PatientsPage';


/**
 * Deleting patients is the one action on this screen that destroys something, so
 * the parts worth pinning are the guards: that the checkboxes only exist for
 * someone allowed to manage patients, and that the confirmation says which
 * patients would really be deleted and which only archived — before it happens.
 */
vi.mock('@/api/patients', () => ({
  createPatient: vi.fn(),
  fetchPatient: vi.fn(),
  fetchPatients: vi.fn(),
  fetchPatientCelebrations: vi.fn(),
  fetchPendingFollowUps: vi.fn(),
  previewPatientDeletion: vi.fn(),
  deletePatient: vi.fn(),
}));

function patient(id: number, name: string): Patient {
  return {
    id,
    name,
    id_number: `17123456${id}`,
    birth_date: '1988-03-15',
    age: 38,
    gender: 'female',
    phone: '0991234567',
    email: null,
    city: 'Quito',
    address: 'Av. Amazonas N34-120',
    last_visit_at: null,
  };
}

function workspace(permissions: Permission[]): Workspace {
  return {
    id: 4,
    name: 'Clínica Prueba',
    type: 'clinic',
    is_clinic: true,
    entitlement: {
      state: 'active',
      effective_access: 'full',
      can_write: true,
      plan_code: 'clinic',
      trial_ends_at: null,
      access_ends_at: null,
      clinical_seats: { included: 2, active_doctors: 1, additional: 0 },
      bundle_eligible: false,
      is_legacy_review: false,
    },
    logo_path: null,
    sri_enabled: false,
    membership_role: 'doctor',
    roles: ['doctor'],
    is_owner: false,
    permissions,
  };
}

function page(patients: Patient[]): Paginated<Patient> {
  return {
    data: patients,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: 1,
      last_page: 1,
      per_page: 15,
      to: patients.length,
      total: patients.length,
    },
  };
}

function renderPage(ws: Workspace) {
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: vi.fn((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    })),
  });
  useAuthStore.setState({ activeWorkspaceId: ws.id, workspaces: [ws] });
  vi.mocked(fetchPatient).mockResolvedValue(patient(1, 'Paciente de prueba'));

  return render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
      <MemoryRouter initialEntries={['/pacientes']}>
        <PageActionProvider>
          <PatientsPage />
        </PageActionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, workspaces: [] });
});

describe('Eliminar pacientes', () => {
  // Retirado el 2026-10-11 a pedido del usuario: con la clínica en uso real,
  // un botón que vacía el directorio entero es un riesgo que no se necesita.
  it('ya no ofrece «Eliminar todos», ni siquiera al dueño', async () => {
    vi.mocked(fetchPatients).mockResolvedValue(page([patient(1, 'Ana Ruiz'), patient(2, 'Luis Mora')]));

    renderPage(workspace(['patients.view', 'patients.manage', 'patients.purge' as Permission]));

    expect(await screen.findByText('Ana Ruiz')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Eliminar todos los pacientes' })).toBeNull();
    expect(screen.queryByText('Eliminar todos')).toBeNull();
  });

  it('no ofrece casillas de selección a quien no puede gestionar pacientes', async () => {
    vi.mocked(fetchPatients).mockResolvedValue(page([patient(1, 'Ana Ruiz')]));

    renderPage(workspace(['patients.view']));

    expect(await screen.findByText('Ana Ruiz')).toBeTruthy();
    expect(screen.queryByRole('checkbox', { name: 'Marcar a Ana Ruiz' })).toBeNull();
  });

  it('dice cuáles se eliminan y cuáles se archivan antes de tocar nada', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatients).mockResolvedValue(
      page([patient(1, 'Ana Ruiz'), patient(2, 'Luis Mora')]),
    );
    vi.mocked(previewPatientDeletion).mockResolvedValue({
      deletable: [{ id: 1, name: 'Ana Ruiz' }],
      archivable: [{ id: 2, name: 'Luis Mora' }],
    });
    vi.mocked(deletePatient).mockResolvedValue({ outcome: 'deleted' });

    renderPage(workspace(['patients.view', 'patients.manage']));

    await user.click(await screen.findByRole('checkbox', { name: 'Marcar a Ana Ruiz' }));
    await user.click(screen.getByRole('checkbox', { name: 'Marcar a Luis Mora' }));

    expect(screen.getByText('2')).toBeTruthy();

    await user.click(screen.getByRole('button', { name: /Eliminar/ }));

    // The split is stated before confirming, not reported afterwards.
    expect(await screen.findByText('Se eliminan (1)')).toBeTruthy();
    expect(screen.getByText('Se archivan (1)')).toBeTruthy();

    // And nothing has been deleted just by opening the dialog.
    expect(deletePatient).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Confirmar' }));

    await waitFor(() => {
      expect(deletePatient).toHaveBeenCalledTimes(2);
    });
    expect(vi.mocked(deletePatient).mock.calls.map(([id]) => id)).toEqual([1, 2]);
  });

  it('la barra de selección solo aparece cuando hay algo marcado', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatients).mockResolvedValue(page([patient(1, 'Ana Ruiz')]));

    renderPage(workspace(['patients.view', 'patients.manage']));

    await screen.findByText('Ana Ruiz');
    expect(screen.queryByRole('button', { name: 'Quitar selección' })).toBeNull();

    await user.click(screen.getByRole('checkbox', { name: 'Marcar a Ana Ruiz' }));
    expect(screen.getByRole('button', { name: 'Quitar selección' })).toBeTruthy();

    await user.click(screen.getByRole('button', { name: 'Quitar selección' }));
    expect(screen.queryByRole('button', { name: 'Quitar selección' })).toBeNull();
  });

  it('keeps failed patients selected so they can be retried', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatients).mockResolvedValue(
      page([patient(1, 'Ana Ruiz'), patient(2, 'Luis Mora')]),
    );
    vi.mocked(previewPatientDeletion).mockResolvedValue({
      deletable: [
        { id: 1, name: 'Ana Ruiz' },
        { id: 2, name: 'Luis Mora' },
      ],
      archivable: [],
    });
    vi.mocked(deletePatient)
      .mockResolvedValueOnce({ outcome: 'deleted' })
      .mockRejectedValueOnce(new Error('Connection interrupted'));

    renderPage(workspace(['patients.view', 'patients.manage']));

    await user.click(await screen.findByRole('checkbox', { name: 'Marcar a Ana Ruiz' }));
    await user.click(screen.getByRole('checkbox', { name: 'Marcar a Luis Mora' }));
    await user.click(screen.getByRole('button', { name: /Eliminar/ }));
    await user.click(await screen.findByRole('button', { name: 'Confirmar' }));

    await waitFor(() => expect(deletePatient).toHaveBeenCalledTimes(2));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());

    // A transient failure must not make the doctor find the patient again
    // before retrying. The successfully processed row leaves the selection.
    expect(screen.getByRole('button', { name: 'Quitar selección' })).toBeTruthy();
    expect((screen.getByRole('checkbox', { name: 'Marcar a Luis Mora' }) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByRole('checkbox', { name: 'Marcar a Ana Ruiz' }) as HTMLInputElement).checked).toBe(false);
  });
});
