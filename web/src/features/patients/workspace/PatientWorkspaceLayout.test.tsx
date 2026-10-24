// src/features/patients/workspace/PatientWorkspaceLayout.test.tsx
// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchPatient } from '@/api/patients';
import { useEncounterGuardStore } from '@/store/encounterGuard';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { PatientWorkspaceLayout } from './PatientWorkspaceLayout';

vi.mock('@/api/patients');

beforeEach(() => {
  // A doctor by default: the atención is theirs (see the nursing test below).
  useAuthStore.setState({ workspaces: [{ id: 1, name: 'Consultorio', is_clinic: false, permissions: ['records.manage', 'records.view'], roles: [] } as never], activeWorkspaceId: 1 });
});

afterEach(() => {
  cleanup();
  useEncounterGuardStore.setState({ hasUnsavedWork: false });
  useAuthStore.setState({ workspaces: [], activeWorkspaceId: null, user: null });
  useUiStore.setState({ quickSearchOpen: false });
});

function renderLayout() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/pacientes/12/atencion']}>
        <Routes>
          <Route path="/pacientes/:patientId" element={<PatientWorkspaceLayout />}>
            <Route path="atencion" element={<div>atención</div>} />
            <Route path="resumen" element={<div>resumen del paciente</div>} />
          </Route>
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('PatientWorkspaceLayout by role', () => {
  it('sends whoever cannot attend to the summary instead of an atención form', async () => {
    vi.mocked(fetchPatient).mockResolvedValue({ id: 12, name: 'Ana Reyes' } as never);
    useAuthStore.setState({
      workspaces: [{ id: 1, name: 'Clínica', is_clinic: true, permissions: ['patients.view', 'vitals.create'], roles: ['nurse'] } as never],
      activeWorkspaceId: 1,
    });
    renderLayout();

    expect(await screen.findByText('resumen del paciente')).toBeTruthy();
    expect(screen.queryByText('atención')).toBeNull();
  });
});

describe('PatientWorkspaceLayout back link', () => {
  it('keeps the patient header and section navigation together, without app controls', async () => {
    vi.mocked(fetchPatient).mockResolvedValue({ id: 12, name: 'Ana Reyes' } as never);
    renderLayout();

    expect(await screen.findByRole('heading', { name: 'Ana Reyes' })).toBeTruthy();
    // The name is shown once, and the file carries no search or account bar:
    // inside a patient the doctor only attends.
    expect(screen.getAllByText('Ana Reyes')).toHaveLength(1);
    expect(screen.queryByRole('textbox', { name: /buscar/i })).toBeNull();
    expect(screen.getByRole('navigation', { name: 'Secciones del expediente' })).toBeTruthy();
  });

  it('confirms before leaving an open, unsaved consultation', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatient).mockResolvedValue({ id: 12, name: 'Ana Reyes' } as never);
    useEncounterGuardStore.setState({ hasUnsavedWork: true });
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

    renderLayout();
    await user.click(await screen.findByRole('link', { name: /Pacientes/i }));

    expect(confirmSpy).toHaveBeenCalled();
    confirmSpy.mockRestore();
  });
});

// A stand-in with its own state: what matters here is that the layout keeps
// the atención mounted, not what the atención contains.
vi.mock('@/features/encounters/EncounterSection', async () => {
  const { useState } = await import('react');
  return {
    EncounterSection: () => {
      const [value, setValue] = useState('');
      return <input aria-label="Motivo de prueba" value={value} onChange={(e) => setValue(e.target.value)} />;
    },
  };
});

describe('PatientWorkspaceLayout — the atención stays open', () => {
  it('keeps what was typed in the atención while the doctor visits another section', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatient).mockResolvedValue({ id: 12, name: 'Ana Reyes' } as never);
    useAuthStore.setState({
      workspaces: [{ id: 1, name: 'Consultorio', is_clinic: false, permissions: ['records.manage', 'records.view'], roles: [] } as never],
      activeWorkspaceId: 1,
    });
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <MemoryRouter initialEntries={['/pacientes/12/atencion']}>
          <Routes>
            <Route path="/pacientes/:patientId" element={<PatientWorkspaceLayout />}>
              <Route path="atencion" element={null} />
              <Route path="historial" element={<div>Historial del paciente</div>} />
            </Route>
          </Routes>
        </MemoryRouter>
      </QueryClientProvider>,
    );

    await user.type(await screen.findByLabelText('Motivo de prueba'), 'Cefalea');
    await user.click(screen.getByRole('link', { name: 'Historial' }));
    expect(await screen.findByText('Historial del paciente')).toBeTruthy();
    // Still mounted — only hidden — so nothing typed is lost.
    expect(screen.getByLabelText('Motivo de prueba').closest('[hidden]')).not.toBeNull();

    await user.click(screen.getByRole('link', { name: /atención/i }));
    const field = (await screen.findByLabelText('Motivo de prueba')) as HTMLInputElement;
    expect(field.closest('[hidden]')).toBeNull();
    expect(field.value).toBe('Cefalea');
  });
});
