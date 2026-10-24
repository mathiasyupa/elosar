// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchPatient } from '@/api/patients';
import type { Patient } from '@/api/types';
import { useAuthStore } from '@/store/auth';
import { PatientPreviewPanel } from './PatientPreviewPanel';

vi.mock('@/api/patients', () => ({
  fetchPatient: vi.fn(),
}));

const listPatient: Patient = {
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
  ...listPatient,
  civil_status: 'single',
  blood_type: 'O+',
  emergency_contact_name: 'Ana López',
  emergency_contact_phone: '0987654321',
  insurance_provider: null,
  insurance_policy_number: null,
  personal_background: null,
  allergies: [],
  family_background: [],
};

function renderPreview({
  canViewRecords = true,
  canManageRecords = true,
}: {
  canViewRecords?: boolean;
  canManageRecords?: boolean;
} = {}) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <PatientPreviewPanel
          patient={listPatient}
          canViewRecords={canViewRecords}
          canManageRecords={canManageRecords}
        />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null });
});

describe('PatientPreviewPanel', () => {
  it('keeps clinical detail unknown while the detailed request is pending', () => {
    vi.mocked(fetchPatient).mockReturnValue(new Promise(() => undefined));
    useAuthStore.setState({ activeWorkspaceId: 4 });

    renderPreview();

    expect(screen.getByText('María López')).toBeTruthy();
    expect(screen.getByText(/Cargando detalle del paciente/i)).toBeTruthy();
    expect(screen.queryByText('Sin alergias')).toBeNull();
    expect(screen.queryByText('Particular')).toBeNull();
  });

  it('shows a retryable error and replaces it with confirmed detail after retry', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchPatient)
      .mockRejectedValueOnce(new Error('network'))
      .mockResolvedValueOnce(detailedPatient);
    useAuthStore.setState({ activeWorkspaceId: 4 });

    renderPreview();

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.queryByText('Sin alergias')).toBeNull();
    expect(screen.queryByText('Particular')).toBeNull();

    await user.click(screen.getByRole('button', { name: 'Reintentar' }));

    expect(await screen.findByText('Sin alergias')).toBeTruthy();
    expect(screen.getByText('Particular')).toBeTruthy();
  });

  it('renders allergies only after the detailed response confirms them', async () => {
    vi.mocked(fetchPatient).mockResolvedValue({
      ...detailedPatient,
      allergies: [
        {
          id: 7,
          allergen_type: 'medication',
          allergen_name: 'Penicilina',
          severity: 'high',
          notes: null,
        },
      ],
    });

    renderPreview();

    expect(await screen.findByText('Penicilina')).toBeTruthy();
    expect(screen.queryByText('Sin alergias')).toBeNull();
  });

  it('states that there are no allergies only after a confirmed empty array', async () => {
    vi.mocked(fetchPatient).mockResolvedValue(detailedPatient);

    renderPreview();

    expect(await screen.findByText('Sin alergias')).toBeTruthy();
    expect(screen.getByText('Particular')).toBeTruthy();
  });

  it('hides clinical shortcuts when their explicit capabilities are absent', async () => {
    vi.mocked(fetchPatient).mockResolvedValue(detailedPatient);

    renderPreview({ canViewRecords: false, canManageRecords: false });

    expect(await screen.findByText('Sin alergias')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Resumen' })).toBeTruthy();
    expect(screen.queryByRole('link', { name: 'Historial' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Consulta' })).toBeNull();
    expect(screen.queryByRole('link', { name: 'Recetas' })).toBeNull();
  });

  it('opens expediente sections in the patient’s named workspace tab', async () => {
    vi.mocked(fetchPatient).mockResolvedValue(detailedPatient);

    renderPreview();

    const summary = await screen.findByRole('link', { name: 'Resumen' });
    expect(summary.getAttribute('href')).toBe('/pacientes/12/resumen');
    expect(summary.getAttribute('target')).toBe('patient-workspace-12');

    const open = screen.getByRole('link', { name: 'Abrir expediente' });
    expect(open.getAttribute('target')).toBe('patient-workspace-12');
    expect(screen.getByRole('link', { name: 'Consulta' }).getAttribute('target')).toBe('patient-workspace-12');
  });
});
