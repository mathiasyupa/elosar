// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { Patient } from '@/api/types';
import { PatientHistoryTab } from './PatientHistoryTab';

vi.mock('./context', () => ({
  usePatientWorkspace: () => ({ patient: { id: 25, name: 'Paciente Prueba' } as Patient, patientId: 25 }),
}));
vi.mock('@/api/patients', () => ({ fetchPatientRecords: vi.fn() }));
vi.mock('@/api/records', () => ({ voidRecord: vi.fn() }));
const perms = { list: ['records.manage', 'records.view'] };
vi.mock('@/lib/capabilities', () => ({
  useWorkspaceCapabilities: () => ({ can: (p: string) => perms.list.includes(p) }),
}));

const { fetchPatientRecords } = await import('@/api/patients');
const { voidRecord } = await import('@/api/records');

function renderTab() {
  render(
    <MemoryRouter>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
        <PatientHistoryTab />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  perms.list = ['records.manage', 'records.view'];
  vi.mocked(fetchPatientRecords).mockResolvedValue({
    data: [{ id: 46, created_at: '2026-10-12T10:00:00', reason_for_consultation: 'Cefalea', diagnoses: [], prescriptions: [] }],
  } as never);
  vi.mocked(voidRecord).mockResolvedValue();
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('Historial — anular una atención', () => {
  it('asks why in the page and voids with that reason', async () => {
    const user = userEvent.setup();
    renderTab();

    await user.click(await screen.findByRole('button', { name: /Anular/ }));
    expect(screen.queryByRole('dialog')).toBeNull();
    const submit = screen.getByRole('button', { name: 'Anular atención' }) as HTMLButtonElement;
    expect(submit.disabled).toBe(true);

    await user.type(screen.getByLabelText('¿Por qué la anulas?'), 'Se creó por error');
    await user.click(submit);

    await waitFor(() => expect(voidRecord).toHaveBeenCalledWith(46, 'Se creó por error'));
  });

  it('is not offered to who cannot write records', async () => {
    perms.list = ['records.view'];
    renderTab();

    expect(await screen.findByText('Cefalea')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Anular/ })).toBeNull();
  });
});
