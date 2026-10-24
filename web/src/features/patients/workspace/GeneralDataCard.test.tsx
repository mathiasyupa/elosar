// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { updatePatient } from '@/api/patients';
import type { Patient } from '@/api/types';
import { useAuthStore } from '@/store/auth';
import { GeneralDataCard } from './GeneralDataCard';

vi.mock('@/api/patients', () => ({
  updatePatient: vi.fn(),
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
  civil_status: 'single',
  blood_type: 'O+',
  emergency_contact_name: 'Ana López',
  emergency_contact_phone: '0987654321',
  insurance_provider: 'Seguro Uno',
  insurance_policy_number: 'POL-12',
  personal_background: null,
  allergies: [],
  family_background: [],
};

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((resolver) => {
    resolve = resolver;
  });
  return { promise, resolve };
}

function renderCard() {
  const queryClient = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <GeneralDataCard patient={patient} />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null });
});

describe('GeneralDataCard', () => {
  it('shows read-only data first, with no form on screen', () => {
    renderCard();

    expect(screen.getByText('Nombre completo')).toBeTruthy();
    expect(screen.getByText('María López')).toBeTruthy();
    expect(screen.queryByLabelText(/Nombre completo/i)).toBeNull();
    expect(screen.getByRole('button', { name: /Editar/i })).toBeTruthy();
  });

  it('turns into an inline form on "Editar", labelling every field, and back to read-only on Cancelar', async () => {
    const user = userEvent.setup();
    renderCard();

    await user.click(screen.getByRole('button', { name: /Editar/i }));

    expect(screen.getByLabelText(/Nombre completo/i)).toBeTruthy();
    expect(screen.getByLabelText(/Cédula/i)).toBeTruthy();
    expect(screen.getByLabelText(/Fecha de nacimiento/i)).toBeTruthy();
    expect(screen.getByRole('combobox', { name: /Sexo/i })).toBeTruthy();
    expect(screen.getByRole('combobox', { name: /Estado civil/i })).toBeTruthy();
    expect(screen.getByLabelText(/Tipo de sangre/i)).toBeTruthy();
    expect(screen.getByLabelText('Dirección')).toBeTruthy();
    expect(screen.getByLabelText('Correo')).toBeTruthy();
    expect(screen.getByLabelText('Teléfono', { selector: '#general-data-phone' })).toBeTruthy();
    expect(screen.getByLabelText('Contacto de emergencia')).toBeTruthy();
    expect(screen.getByLabelText('Teléfono de emergencia')).toBeTruthy();
    expect(screen.getByLabelText('Aseguradora')).toBeTruthy();
    expect(screen.getByLabelText('N.º de póliza')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Guardar' })).toBeTruthy();

    await user.click(screen.getByRole('button', { name: 'Cancelar' }));

    expect(screen.queryByLabelText(/Nombre completo/i)).toBeNull();
    expect(screen.getByRole('button', { name: /Editar/i })).toBeTruthy();
  });

  // No hay overlay/Escape que cerrar en línea (a diferencia del modal que
  // reemplaza) — el equivalente correcto es que Cancelar quede inhabilitado
  // mientras la mutación está en curso.
  it('disables Cancelar while saving, and keeps editing until the save resolves', async () => {
    const user = userEvent.setup();
    const request = deferred<Patient>();
    vi.mocked(updatePatient).mockReturnValue(request.promise);
    useAuthStore.setState({ activeWorkspaceId: 4 });
    renderCard();

    await user.click(screen.getByRole('button', { name: /Editar/i }));
    await user.click(screen.getByRole('button', { name: 'Guardar' }));

    await waitFor(() => {
      expect((screen.getByRole('button', { name: 'Cancelar' }) as HTMLButtonElement).disabled).toBe(true);
    });
    expect((screen.getByRole('button', { name: 'Guardando…' }) as HTMLButtonElement).disabled).toBe(true);
    // Sigue en modo edición: el formulario no desaparece hasta que resuelva.
    expect(screen.getByLabelText(/Nombre completo/i)).toBeTruthy();
  });

  // Bug real encontrado al verificar en el navegador: un <select> nativo
  // siempre manda un valor — un paciente sin estado civil registrado (el caso
  // más común: nadie lo llena al registrar) envía "" en vez de `undefined`.
  // `z.enum(...).optional()` solo perdona la AUSENCIA del campo, así que sin
  // `.or(z.literal(''))` el guardado fallaba la validación de RHF EN
  // SILENCIO — sin error visible, sin request de red, sin que "Guardar"
  // pasara a "Guardando…". Confirmado que este mismo esquema, heredado de
  // `EditPatientModal`, nunca lo cazó porque su test usaba un paciente con
  // `civil_status: 'single'` ya puesto.
  it('saves a patient whose civil_status was never set, without silently failing validation', async () => {
    const user = userEvent.setup();
    const patientWithoutCivilStatus: Patient = { ...patient, civil_status: null };
    vi.mocked(updatePatient).mockResolvedValue(patientWithoutCivilStatus);
    useAuthStore.setState({ activeWorkspaceId: 4 });

    const queryClient = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    render(
      <QueryClientProvider client={queryClient}>
        <GeneralDataCard patient={patientWithoutCivilStatus} />
      </QueryClientProvider>,
    );

    await user.click(screen.getByRole('button', { name: /Editar/i }));
    await user.click(screen.getByRole('button', { name: 'Guardar' }));

    await waitFor(() => expect(updatePatient).toHaveBeenCalledTimes(1));
    await waitFor(() => expect(screen.getByRole('button', { name: /Editar/i })).toBeTruthy());
  });
});
