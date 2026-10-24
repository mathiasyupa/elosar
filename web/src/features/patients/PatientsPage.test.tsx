// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, useLocation } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
  createPatient,
  fetchPatient,
  fetchPatients,
} from '@/api/patients';
import type { Paginated, Patient, Permission, Workspace } from '@/api/types';
import {
  PageActionProvider,
  usePageAction,
} from '@/components/shell/PageActionContext';
import { useAuthStore } from '@/store/auth';
import { PatientsPage } from './PatientsPage';

vi.mock('@/api/patients', () => ({
  createPatient: vi.fn(),
  fetchPatient: vi.fn(),
  fetchPatients: vi.fn(),
  fetchPatientCelebrations: vi.fn(),
  fetchPendingFollowUps: vi.fn(),
}));

vi.mock('./PatientAppointmentsTab', () => ({
  PatientAppointmentsTab: () => <div>Vista de citas seleccionada</div>,
}));

vi.mock('./PendingFollowUps', () => ({
  PendingFollowUps: () => <div>Vista de seguimiento seleccionada</div>,
}));

vi.mock('./PatientCelebrations', () => ({
  PatientCelebrations: () => <div>Vista de felicitaciones seleccionada</div>,
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
  city: 'Quito',
  address: 'Av. Amazonas N34-120',
  last_visit_at: '2026-07-28',
};

const detailedPatient: Patient = {
  ...patient,
  civil_status: 'single',
  blood_type: 'O+',
  emergency_contact_name: null,
  emergency_contact_phone: null,
  insurance_provider: null,
  insurance_policy_number: null,
  personal_background: null,
  allergies: [],
  family_background: [],
};

function workspace(id: number, permissions: Permission[]): Workspace {
  return {
    id,
    name: `Workspace ${id}`,
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
      from: patients.length ? 1 : null,
      last_page: 1,
      per_page: 15,
      to: patients.length || null,
      total: patients.length,
    },
  };
}

function paginatedPage(currentPage: number, lastPage: number, patients: Patient[]): Paginated<Patient> {
  return {
    data: patients,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: currentPage,
      from: patients.length ? (currentPage - 1) * 20 + 1 : null,
      last_page: lastPage,
      per_page: 20,
      to: patients.length ? currentPage * 20 : null,
      total: lastPage * 20,
    },
  };
}

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((resolver) => {
    resolve = resolver;
  });
  return { promise, resolve };
}

function ActionProbe() {
  const actions = usePageAction();
  return <span>{actions.length ? actions.map((a) => a.label).join(', ') : 'Sin acción contextual'}</span>;
}

function LocationProbe() {
  const location = useLocation();
  return <output aria-label="Ubicación actual">{`${location.pathname}${location.search}`}</output>;
}

function setViewportWidth(width: number) {
  Object.defineProperty(window, 'matchMedia', {
    configurable: true,
    writable: true,
    value: vi.fn((query: string) => {
      const maxWidth = query.match(/max-width:\s*(\d+)px/)?.[1];

      return {
        matches: maxWidth ? width <= Number(maxWidth) : false,
        media: query,
        onchange: null,
        addListener: vi.fn(),
        removeListener: vi.fn(),
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
      } satisfies MediaQueryList;
    }),
  });
}

function renderPage({
  activeWorkspace,
  workspaces = [activeWorkspace],
  viewportWidth = 1024,
  initialEntry = '/pacientes',
}: {
  activeWorkspace: Workspace;
  workspaces?: Workspace[];
  viewportWidth?: number;
  initialEntry?: string;
}) {
  setViewportWidth(viewportWidth);
  useAuthStore.setState({
    activeWorkspaceId: activeWorkspace.id,
    workspaces,
  });
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <PageActionProvider>
          <PatientsPage />
          <ActionProbe />
          <LocationProbe />
        </PageActionProvider>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return { ...rendered, queryClient };
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.restoreAllMocks();
  document.body.classList.remove('patient-preview-modal-open');
  useAuthStore.setState({
    activeWorkspaceId: null,
    workspaces: [],
  });
});

describe('PatientsPage', () => {
  it('navigates patient views in lifecycle order while preserving the route contract', async () => {
    const user = userEvent.setup();
    const clinicalWorkspace = workspace(4, ['patients.view']);
    vi.mocked(fetchPatients).mockResolvedValue(page([]));

    renderPage({ activeWorkspace: clinicalWorkspace });

    const buttonLabels = Array.from(document.querySelectorAll('.patients-view-tabs button'))
      .map((button) => button.textContent?.trim());
    expect(buttonLabels).toEqual([
      'Lista de pacientes',
      'Citas y atenciones',
      'Seguimiento pendiente',
      'Felicita a tu paciente',
    ]);

    await user.click(screen.getByRole('button', { name: 'Seguimiento pendiente' }));
    expect(screen.getByLabelText('Ubicación actual').textContent).toBe('/pacientes?view=follow-ups');
    expect(screen.getByText('Vista de seguimiento seleccionada')).toBeTruthy();

    await user.click(screen.getByRole('button', { name: 'Lista de pacientes' }));
    expect(screen.getByLabelText('Ubicación actual').textContent).toBe('/pacientes');
  });

  it('selects the celebrations view from its stable URL before interaction', () => {
    const clinicalWorkspace = workspace(4, ['patients.view']);

    renderPage({
      activeWorkspace: clinicalWorkspace,
      initialEntry: '/pacientes?view=celebrations',
    });

    expect(screen.getByText('Vista de felicitaciones seleccionada')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Felicita a tu paciente' }).classList).toContain('active');
  });

  it('preserves unrelated query parameters while switching patient views', async () => {
    const user = userEvent.setup();
    const clinicalWorkspace = workspace(4, ['patients.view']);
    vi.mocked(fetchPatients).mockResolvedValue(page([]));

    renderPage({
      activeWorkspace: clinicalWorkspace,
      initialEntry: '/pacientes?q=Ana',
    });

    await user.click(screen.getByRole('button', { name: 'Seguimiento pendiente' }));
    expect(screen.getByLabelText('Ubicación actual').textContent).toBe('/pacientes?q=Ana&view=follow-ups');

    await user.click(screen.getByRole('button', { name: 'Lista de pacientes' }));
    expect(screen.getByLabelText('Ubicación actual').textContent).toBe('/pacientes?q=Ana');
  });

  it('hides patient creation actions without patients.manage', async () => {
    vi.mocked(fetchPatients).mockResolvedValue(page([]));
    const restrictedWorkspace = workspace(4, ['patients.view']);

    renderPage({ activeWorkspace: restrictedWorkspace });

    expect(await screen.findByText('Sin acción contextual')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Registrar paciente' })).toBeNull();
  });

  it('exposes patient creation actions with patients.manage', async () => {
    vi.mocked(fetchPatients).mockResolvedValue(page([]));
    const managingWorkspace = workspace(4, ['patients.view', 'patients.manage']);

    renderPage({ activeWorkspace: managingWorkspace });

    expect(await screen.findByText(/Nuevo paciente/)).toBeTruthy();
    expect(
      await screen.findByRole('button', { name: 'Registrar paciente' }),
    ).toBeTruthy();
  });

  it('keeps the patient list and offers the new expediente in a separate tab', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatients).mockResolvedValue(page([]));
    vi.mocked(createPatient).mockResolvedValue(patient);
    const openTab = vi.spyOn(window, 'open').mockReturnValue(null);
    renderPage({ activeWorkspace: workspace(4, ['patients.view', 'patients.manage']) });

    await user.click(await screen.findByRole('button', { name: 'Registrar paciente' }));
    await user.type(screen.getByLabelText('Nombre completo'), patient.name);
    await user.type(screen.getByLabelText('Cédula'), '1712345678');
    await user.type(screen.getByLabelText('Fecha de nacimiento'), '1988-03-15');
    await user.type(screen.getByLabelText('Dirección'), patient.address ?? 'Quito');
    await user.type(screen.getByLabelText('Teléfono'), patient.phone ?? '0991234567');
    await user.click(screen.getByRole('button', { name: 'Crear paciente' }));

    await waitFor(() => expect(openTab).toHaveBeenCalledWith('/pacientes/12/resumen', 'patient-workspace-12'));
    expect(screen.getByLabelText('Ubicación actual').textContent).toBe('/pacientes');
    const link = screen.getByRole('link', { name: /Abrir expediente/i });
    expect(link.getAttribute('href')).toBe('/pacientes/12/resumen');
    expect(link.getAttribute('target')).toBe('patient-workspace-12');
  });

  it('uses the mobile modal contract at 740px without leaving the desktop aside', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatients).mockResolvedValue(page([patient]));
    vi.mocked(fetchPatient).mockResolvedValue(detailedPatient);
    const clinicalWorkspace = workspace(4, [
      'patients.view',
      'patients.manage',
      'records.view',
      'records.manage',
    ]);

    renderPage({ activeWorkspace: clinicalWorkspace, viewportWidth: 740 });

    const selectButton = await screen.findByRole('button', {
      name: 'Seleccionar a María López',
    });
    expect(document.querySelector('.patients-aside')).toBeNull();
    await user.click(selectButton);

    const firstDialog = await screen.findByRole('dialog', {
      name: 'Vista previa de María López',
    });
    const background = document.querySelector<HTMLElement>('.patients-page-content');
    expect(firstDialog.contains(document.activeElement)).toBe(true);
    expect(background?.hasAttribute('inert')).toBe(true);
    expect(background?.getAttribute('aria-hidden')).toBe('true');
    expect(document.body.classList.contains('patient-preview-modal-open')).toBe(true);

    await user.tab();
    expect(firstDialog.contains(document.activeElement)).toBe(true);
    await user.keyboard('{Escape}');

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
    expect(document.activeElement).toBe(selectButton);
    expect(background?.hasAttribute('inert')).toBe(false);
    expect(background?.hasAttribute('aria-hidden')).toBe(false);
    expect(document.body.classList.contains('patient-preview-modal-open')).toBe(false);

    await user.click(selectButton);
    await screen.findByRole('dialog', { name: 'Vista previa de María López' });
    await user.click(document.querySelector<HTMLElement>('.modal-overlay')!);

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
    expect(document.activeElement).toBe(selectButton);
  });

  it('keeps the persistent desktop aside above 760px without opening a modal', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatients).mockResolvedValue(page([patient]));
    vi.mocked(fetchPatient).mockResolvedValue(detailedPatient);
    const clinicalWorkspace = workspace(4, [
      'patients.view',
      'records.view',
      'records.manage',
    ]);

    renderPage({ activeWorkspace: clinicalWorkspace, viewportWidth: 761 });

    const selectButton = await screen.findByRole('button', {
      name: 'Seleccionar a María López',
    });
    const aside = document.querySelector<HTMLElement>('.patients-aside');
    expect(aside).not.toBeNull();
    expect(await within(aside!).findByText('María López')).toBeTruthy();

    await user.click(selectButton);

    expect(screen.queryByRole('dialog')).toBeNull();
    expect(document.querySelector('.patients-aside')).toBe(aside);
  });

  it('removes the previous workspace patient while the next workspace is loading', async () => {
    const nextWorkspacePatients = deferred<Paginated<Patient>>();
    const firstWorkspace = workspace(1, ['patients.view']);
    const secondWorkspace = workspace(2, ['patients.view']);
    vi.mocked(fetchPatients)
      .mockResolvedValueOnce(page([{ ...patient, id: 31, name: 'Paciente Uno' }]))
      .mockReturnValueOnce(nextWorkspacePatients.promise);
    vi.mocked(fetchPatient).mockResolvedValue({
      ...detailedPatient,
      id: 31,
      name: 'Paciente Uno',
    });

    renderPage({
      activeWorkspace: firstWorkspace,
      workspaces: [firstWorkspace, secondWorkspace],
    });

    expect(
      await screen.findByRole('button', { name: 'Seleccionar a Paciente Uno' }),
    ).toBeTruthy();

    act(() => {
      useAuthStore.getState().setActiveWorkspace(secondWorkspace.id);
    });

    expect(
      screen.queryByRole('button', { name: 'Seleccionar a Paciente Uno' }),
    ).toBeNull();
    expect(screen.queryByText('Paciente Uno')).toBeNull();

    nextWorkspacePatients.resolve(page([]));
  });

  it('keeps page controls enabled during a background refresh of the current page', async () => {
    const user = userEvent.setup();
    const refresh = deferred<Paginated<Patient>>();
    const managingWorkspace = workspace(4, ['patients.view', 'patients.manage']);

    vi.mocked(fetchPatients)
      .mockResolvedValueOnce(paginatedPage(1, 34, [patient]))
      .mockResolvedValueOnce(paginatedPage(2, 34, [{ ...patient, id: 13, name: 'Paciente Dos' }]))
      .mockReturnValueOnce(refresh.promise);

    const { queryClient } = renderPage({ activeWorkspace: managingWorkspace });

    await screen.findByRole('button', { name: 'Siguiente' });
    await user.click(screen.getByRole('button', { name: 'Siguiente' }));
    await screen.findByText(/Página 2 de 34/);

    void queryClient.invalidateQueries({ queryKey: ['patients'] });

    await waitFor(() => {
      expect(vi.mocked(fetchPatients)).toHaveBeenCalledTimes(3);
    });

    expect((screen.getByRole('button', { name: 'Anterior' }) as HTMLButtonElement).disabled).toBe(false);
    expect((screen.getByRole('button', { name: 'Siguiente' }) as HTMLButtonElement).disabled).toBe(false);

    refresh.resolve(paginatedPage(2, 34, [{ ...patient, id: 13, name: 'Paciente Dos' }]));
  });

  it('requests patients attended within the selected consultation dates', async () => {
    vi.mocked(fetchPatients).mockResolvedValue(page([patient]));
    const clinicalWorkspace = workspace(4, ['patients.view']);

    renderPage({ activeWorkspace: clinicalWorkspace });

    const from = await screen.findByLabelText('Atendidos desde');
    fireEvent.change(from, { target: { value: '2026-08-14' } });

    await waitFor(() => {
      expect(vi.mocked(fetchPatients)).toHaveBeenLastCalledWith('', 1, undefined, {
        attendedFrom: '2026-08-14',
        attendedTo: undefined,
      });
    });
  });

  it('groups the clinical-attendance date filters under one labelled region', async () => {
    vi.mocked(fetchPatients).mockResolvedValue(page([patient]));
    const clinicalWorkspace = workspace(4, ['patients.view']);

    renderPage({ activeWorkspace: clinicalWorkspace });

    const filters = await screen.findByRole('group', { name: 'Filtrar por atención clínica' });
    expect(within(filters).getByLabelText('Atendidos desde')).toBeTruthy();
    expect(within(filters).getByLabelText('Hasta')).toBeTruthy();
  });
});
