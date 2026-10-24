// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchAppointments } from '@/api/appointments';
import type { Appointment, Patient } from '@/api/types';
import { PatientAppointmentsTab } from './PatientAppointmentsTab';

vi.mock('@/api/appointments', () => ({
  fetchAppointments: vi.fn(),
  markReminded: vi.fn(),
  sendReminderEmail: vi.fn(),
}));

function patient(overrides: Partial<Patient>): Patient {
  return {
    id: 1,
    name: 'Paciente',
    id_number: null,
    document_type: null,
    document_country: null,
    birth_date: null,
    age: null,
    gender: null,
    phone: null,
    email: null,
    city: null,
    address: null,
    ...overrides,
  };
}

const appointments: Appointment[] = [
  {
    id: 101,
    appointment_date: '2026-08-20T15:00:00',
    status: 'scheduled',
    status_label: 'Programada',
    type: 'Consulta',
    medical_service_id: null,
    service_name: null,
    channel: null,
    duration_minutes: 30,
    notes: null,
    confirmed_at: null,
    reminder_sent_at: null,
    doctor_name: 'Dra. Sofía Ramírez',
    patient: patient({ id: 12, name: 'María López', id_number: '1712345678' }),
  },
  {
    id: 102,
    appointment_date: '2026-08-14T10:00:00',
    status: 'completed',
    status_label: 'Completada',
    type: 'Control',
    medical_service_id: null,
    service_name: null,
    channel: null,
    duration_minutes: 20,
    notes: null,
    confirmed_at: null,
    reminder_sent_at: null,
    doctor_name: 'Dr. Andrés Peña',
    patient: patient({ id: 13, name: 'José Peña', id_number: '0912345678' }),
  },
];

function renderTab() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <PatientAppointmentsTab />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('PatientAppointmentsTab order', () => {
  it('lists the most recent first', async () => {
    // The API sends them oldest first (that is what the agenda wants).
    vi.mocked(fetchAppointments).mockResolvedValue([...appointments].reverse());
    renderTab();

    await screen.findByText('María López');
    const text = document.body.textContent ?? '';
    expect(text.indexOf('María López')).toBeLessThan(text.indexOf('José Peña'));
  });
});

describe('PatientAppointmentsTab search', () => {
  it('filters by patient name, ignoring accents and case', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchAppointments).mockResolvedValue(appointments);

    renderTab();

    expect(await screen.findByText('María López')).toBeTruthy();
    expect(screen.getByText('José Peña')).toBeTruthy();

    await user.type(screen.getByPlaceholderText(/buscar/i), 'jose');

    await waitFor(() => {
      expect(screen.queryByText('María López')).toBeNull();
    });
    expect(screen.getByText('José Peña')).toBeTruthy();
  });

  it('filters by cédula', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchAppointments).mockResolvedValue(appointments);

    renderTab();

    expect(await screen.findByText('María López')).toBeTruthy();

    await user.type(screen.getByPlaceholderText(/buscar/i), '1712345678');

    await waitFor(() => {
      expect(screen.queryByText('José Peña')).toBeNull();
    });
    expect(screen.getByText('María López')).toBeTruthy();
  });

  it('opens the expediente from the patient name, without opening the edit-cita modal', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchAppointments).mockResolvedValue(appointments);

    renderTab();

    const link = (await screen.findByText('María López')).closest('a');
    expect(link).toBeTruthy();
    expect(link?.getAttribute('href')).toBe('/pacientes/12/resumen');
    expect(link?.getAttribute('target')).toBe('patient-workspace-12');

    await user.click(link!);

    expect(screen.queryByRole('heading', { name: 'Cita' })).toBeNull();
  });

  it('still opens the edit-cita modal when clicking elsewhere in the row', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchAppointments).mockResolvedValue(appointments);

    renderTab();

    await user.click(await screen.findByText('Dra. Sofía Ramírez'));

    expect(await screen.findByRole('heading', { name: 'Cita' })).toBeTruthy();
  });

  it('shows an empty state when the search matches nothing', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchAppointments).mockResolvedValue(appointments);

    renderTab();

    expect(await screen.findByText('María López')).toBeTruthy();

    await user.type(screen.getByPlaceholderText(/buscar/i), 'nadie con este nombre');

    expect(await screen.findByText(/no hay citas/i)).toBeTruthy();
  });
});
