// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchClinicQueue, saveTriageVitals, startTriageDraft, type TriageDraft } from '@/api/clinic';
import type { ClinicQueue } from '@/api/types';
import { TriagePage } from './TriagePage';

vi.mock('@/api/clinic', () => ({
  startTriageDraft: vi.fn(),
  saveTriageVitals: vi.fn(),
  fetchClinicQueue: vi.fn(),
}));

const draft = {
  id: 91,
  appointment_id: 1237,
  patient_id: 12,
  status: 'draft',
  patient: { id: 12, name: 'María López' },
  vitals: [],
} as TriageDraft;

function queue(rows: Array<{ id: number; status: string; name: string }>): ClinicQueue {
  return {
    date: '2026-08-16',
    stats: {} as never,
    appointments: rows.map((r) => ({
      id: r.id,
      time: '09:00',
      status: r.status as never,
      checked_in_at: null,
      doctor: null,
      patient: { id: r.id * 10, name: r.name } as never,
    })),
  };
}

function renderTriage() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/triaje/1237']}>
        <Routes>
          <Route path="/triaje/:appointmentId" element={<TriagePage />} />
          <Route path="/cola" element={<span data-testid="landed">cola</span>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(startTriageDraft).mockResolvedValue(draft);
  vi.mocked(saveTriageVitals).mockResolvedValue(draft);
  vi.mocked(fetchClinicQueue).mockResolvedValue(queue([]));
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('TriagePage', () => {
  it('restores the saved Score MAMÁ fields when triage is reopened', async () => {
    vi.mocked(startTriageDraft).mockResolvedValue({
      ...draft,
      vitals: [{
        id: 7,
        oxygen_therapy: 'nasal_cannula',
        consciousness_level: 'responds_to_voice',
        urine_output_ml_h: '28',
        urine_output_not_quantifiable: false,
      }],
    } as unknown as TriageDraft);
    const user = userEvent.setup();
    renderTriage();

    await user.click(await screen.findByLabelText(/Aplicar Score MAMÁ/i));

    expect((screen.getByLabelText('Oxigenoterapia') as HTMLSelectElement).value).toBe('nasal_cannula');
    expect((screen.getByLabelText('Nivel de consciencia') as HTMLSelectElement).value).toBe('responds_to_voice');
    expect((screen.getByLabelText('Gasto urinario (mL/h)') as HTMLInputElement).value).toBe('28');
  });

  it('restores a saved non-quantifiable urine output when triage is reopened', async () => {
    vi.mocked(startTriageDraft).mockResolvedValue({
      ...draft,
      vitals: [{
        id: 7,
        urine_output_not_quantifiable: true,
      }],
    } as unknown as TriageDraft);
    const user = userEvent.setup();
    renderTriage();

    await user.click(await screen.findByLabelText(/Aplicar Score MAMÁ/i));

    expect((screen.getByLabelText(/No cuantificable/i) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText('Gasto urinario (mL/h)') as HTMLInputElement).disabled).toBe(true);
  });

  // A3 · el médico recibió una pantalla de cierre diseñada; enfermería recibía
  // un toast y un redirect. Hace el mismo trabajo y merece el mismo cierre:
  // decir qué pasó y ofrecer lo siguiente.
  it('confirms what happened instead of bouncing back to the queue', async () => {
    const user = userEvent.setup();
    renderTriage();

    await user.type(await screen.findByLabelText('Peso (kg)'), '68');
    await user.click(screen.getByRole('button', { name: /Guardar signos/i }));

    expect(await screen.findByText(/Signos de María López guardados/i)).toBeTruthy();
    expect(screen.getByText(/listo para el médico/i)).toBeTruthy();
    // No se fue sola: el cierre se queda en pantalla.
    expect(screen.queryByTestId('landed')).toBeNull();
  });

  it('normalizes a decimal comma and marks invalid measurements before saving', async () => {
    const user = userEvent.setup();
    renderTriage();

    const weight = await screen.findByLabelText('Peso (kg)');
    await user.type(weight, 'abc');
    await user.click(screen.getByRole('button', { name: /Guardar signos/i }));
    expect(saveTriageVitals).not.toHaveBeenCalled();
    expect(weight.getAttribute('aria-invalid')).toBe('true');

    await user.clear(weight);
    await user.type(weight, '68,5');
    await user.click(screen.getByRole('button', { name: /Guardar signos/i }));
    await waitFor(() => expect(saveTriageVitals).toHaveBeenCalledWith(91, expect.objectContaining({ weight_kg: '68.5' })));
  });

  it('offers the next patient waiting for triage', async () => {
    const user = userEvent.setup();
    // `vitals_done` ya pasó por enfermería; el que espera es `checked_in`.
    vi.mocked(fetchClinicQueue).mockResolvedValue(
      queue([
        { id: 1237, status: 'vitals_done', name: 'María López' },
        { id: 90, status: 'vitals_done', name: 'Ya triado' },
        { id: 91, status: 'checked_in', name: 'Carlos Ruiz' },
      ]),
    );
    renderTriage();

    await user.click(await screen.findByRole('button', { name: /Guardar signos/i }));

    expect(await screen.findByRole('button', { name: /Triar a Carlos Ruiz/i })).toBeTruthy();
  });

  it('says the queue is clear when nobody else is waiting', async () => {
    const user = userEvent.setup();
    renderTriage();

    await user.click(await screen.findByRole('button', { name: /Guardar signos/i }));

    expect(await screen.findByText(/No queda nadie esperando signos/i)).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Triar a /i })).toBeNull();
  });

  it('always leaves a way back to the queue', async () => {
    const user = userEvent.setup();
    renderTriage();

    await user.click(await screen.findByRole('button', { name: /Guardar signos/i }));
    await user.click(await screen.findByRole('button', { name: /Volver a la cola/i }));

    expect(screen.getByTestId('landed').textContent).toBe('cola');
  });
});
