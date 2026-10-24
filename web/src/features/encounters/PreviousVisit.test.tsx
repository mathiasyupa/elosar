// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchPatientRecords } from '@/api/patients';
import type { MedicalRecord } from '@/api/types';
import { PreviousVisit } from './PreviousVisit';

vi.mock('@/api/patients', () => ({ fetchPatientRecords: vi.fn() }));

function makeRecord(overrides: Partial<MedicalRecord>): MedicalRecord {
  return {
    id: 1,
    status: 'completed',
    created_at: '2026-07-10T10:00:00',
    reason_for_consultation: 'Control de presión',
    diagnosis: 'Hipertensión controlada',
    treatment: 'Losartán 50mg',
    structured_exam: { abdomen: 'Blando, sin masas' },
    vitals: [],
    services: [],
    ...overrides,
  } as unknown as MedicalRecord;
}

function renderPrevious(records: MedicalRecord[], excludeRecordId: number | null = null) {
  vi.mocked(fetchPatientRecords).mockResolvedValue({
    data: records,
    meta: { current_page: 1, last_page: 1, total: records.length },
  } as never);

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <PreviousVisit patientId={12} excludeRecordId={excludeRecordId} />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('PreviousVisit', () => {
  it('summarises the last closed visit without opening it', async () => {
    renderPrevious([makeRecord({ id: 5 })]);

    expect(await screen.findByText('Consulta anterior')).toBeTruthy();
    expect(screen.getByText(/Hipertensión controlada/)).toBeTruthy();
    // Collapsed: the detail is not in the document yet.
    expect(screen.queryByText('Blando, sin masas')).toBeNull();
  });

  it('opens the full detail on demand', async () => {
    const user = userEvent.setup();
    renderPrevious([makeRecord({ id: 5 })]);

    await user.click(await screen.findByRole('button', { name: /Consulta anterior/i }));

    expect(screen.getByText('Blando, sin masas')).toBeTruthy();
    expect(screen.getByText('Losartán 50mg')).toBeTruthy();
  });

  // The draft being completed right now is the CURRENT visit, not a previous one.
  it('skips the draft that is being completed', async () => {
    renderPrevious(
      [
        makeRecord({ id: 9, status: 'draft', diagnosis: 'Borrador de enfermería' }),
        makeRecord({ id: 5, diagnosis: 'Hipertensión controlada' }),
      ],
      9,
    );

    expect(await screen.findByText(/Hipertensión controlada/)).toBeTruthy();
    expect(screen.queryByText(/Borrador de enfermería/)).toBeNull();
  });

  it('shows nothing at all on a patient with no closed visits', async () => {
    renderPrevious([makeRecord({ id: 9, status: 'draft' })]);

    // A first visit must not render an empty shell that looks broken.
    await new Promise((r) => setTimeout(r, 0));
    expect(screen.queryByText('Consulta anterior')).toBeNull();
  });
});
