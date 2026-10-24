// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import { createPatientVitalObservation, fetchBaselineVitals } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { PatientVitalsCard } from './PatientVitalsCard';

vi.mock('@/api/patients', () => ({
  fetchBaselineVitals: vi.fn(),
  createPatientVitalObservation: vi.fn(),
}));

function renderCard() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <PatientVitalsCard patientId={12} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  useAuthStore.setState({
    activeWorkspaceId: 1,
    workspaces: [{
      id: 1,
      permissions: ['patients.view', 'vitals.create'],
      roles: ['receptionist', 'billing', 'nurse'],
    } as never],
  });
  vi.mocked(fetchBaselineVitals).mockResolvedValue(null);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, workspaces: [] });
});

it('lets a combined nursing, reception and cash account save signs without an appointment', async () => {
  const user = userEvent.setup();
  const saved = {
    source: 'standalone',
    head_circumference_cm: null,
    recorded_at: '2026-10-13T10:00:00-05:00',
    weight_kg: '36.50',
    height_cm: null,
    blood_pressure_sys: null,
    blood_pressure_dia: null,
    heart_rate: null,
    temperature: null,
    oxygen_saturation: null,
    respiratory_rate: null,
  } as const;
  vi.mocked(createPatientVitalObservation).mockResolvedValue(saved);
  vi.mocked(fetchBaselineVitals).mockResolvedValueOnce(null).mockResolvedValue(saved);
  renderCard();

  await user.click(await screen.findByRole('button', { name: 'Registrar signos' }));
  await user.type(screen.getByLabelText('Peso (kg)'), '36,5');
  await user.click(screen.getByRole('button', { name: 'Guardar signos vitales' }));

  await waitFor(() => expect(createPatientVitalObservation).toHaveBeenCalledWith(12, { weight_kg: '36.5' }));
  expect(await screen.findByText('36.50 kg')).toBeTruthy();
  expect(screen.queryByRole('button', { name: 'Guardar signos vitales' })).toBeNull();
});

it('keeps invalid values in the form and explains the accepted range', async () => {
  const user = userEvent.setup();
  renderCard();
  await user.click(await screen.findByRole('button', { name: 'Registrar signos' }));
  await user.type(screen.getByLabelText('SpO₂ (%)'), '120');
  await user.click(screen.getByRole('button', { name: 'Guardar signos vitales' }));

  expect(screen.getByText('Ingresa un valor entre 50 y 100.')).toBeTruthy();
  expect(createPatientVitalObservation).not.toHaveBeenCalled();
  expect((screen.getByLabelText('SpO₂ (%)') as HTMLInputElement).value).toBe('120');
});
