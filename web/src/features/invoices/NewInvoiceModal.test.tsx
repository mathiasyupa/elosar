// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchMedicalServices } from '@/api/catalogs';
import { fetchBillingDoctors } from '@/api/billing';
import { useAuthStore } from '@/store/auth';
import type { BillableService, BillingPatient } from '@/api/types';
import { NewInvoiceModal } from './NewInvoiceModal';

vi.mock('@/api/catalogs', () => ({ fetchMedicalServices: vi.fn() }));
vi.mock('@/api/billing', () => ({ fetchBillingDoctors: vi.fn() }));
vi.mock('@/api/invoices', () => ({ createInvoice: vi.fn() }));

const patient: BillingPatient = { id: 12, name: 'María López', id_number: '1712345678' };

function renderModal(initialServices: BillableService[] | null) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <NewInvoiceModal
          initialPatient={patient}
          initialServices={initialServices}
          onClose={vi.fn()}
          onCreated={vi.fn()}
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

describe('NewInvoiceModal prefill', () => {
  it('opens with the services the doctor declared', async () => {
    vi.mocked(fetchMedicalServices).mockResolvedValue([]);
    vi.mocked(fetchBillingDoctors).mockResolvedValue([]);

    renderModal([
      { medical_service_id: 7, name: 'Biometría hemática', category: 'laboratory', quantity: '2', unit_price: '17.50' },
      { medical_service_id: 9, name: 'Curación simple', category: 'procedure', quantity: '1', unit_price: '10.00' },
    ]);

    expect(await screen.findByDisplayValue('Biometría hemática')).toBeTruthy();
    expect(screen.getByDisplayValue('Curación simple')).toBeTruthy();
    expect(screen.getByDisplayValue('17.50')).toBeTruthy();
  });

  // A clinic doctor cannot price their own work, so the service arrives without
  // one. It must still appear — the cash desk has to see the work happened and
  // decide what it costs, not discover the omission after the patient left.
  it('keeps an unpriced service visible at zero instead of dropping it', async () => {
    vi.mocked(fetchMedicalServices).mockResolvedValue([]);
    vi.mocked(fetchBillingDoctors).mockResolvedValue([]);

    renderModal([
      { medical_service_id: null, name: 'Curación', category: 'procedure', quantity: '1', unit_price: null },
    ]);

    expect(await screen.findByDisplayValue('Curación')).toBeTruthy();
    expect(screen.getByDisplayValue('0')).toBeTruthy();
  });

  it('still offers a blank line when nothing was declared', async () => {
    vi.mocked(fetchMedicalServices).mockResolvedValue([]);
    vi.mocked(fetchBillingDoctors).mockResolvedValue([]);

    renderModal(null);

    // One empty item row, exactly as before this prefill existed.
    expect(await screen.findByText('María López')).toBeTruthy();
    expect(screen.getAllByDisplayValue('')).not.toHaveLength(0);
    expect(screen.queryByDisplayValue('Biometría hemática')).toBeNull();
  });
});
