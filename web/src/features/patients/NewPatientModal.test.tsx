// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createPatient } from '@/api/patients';
import type { Patient } from '@/api/types';
import { NewPatientModal } from './NewPatientModal';
import { useUiStore } from '@/store/ui';

vi.mock('@/api/patients', () => ({
  createPatient: vi.fn(),
}));

function deferred<T>() {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>((resolver) => {
    resolve = resolver;
  });
  return { promise, resolve };
}

function renderModal(onClose = vi.fn()) {
  const queryClient = new QueryClient({
    defaultOptions: { mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <NewPatientModal onClose={onClose} onCreated={vi.fn()} />
    </QueryClientProvider>,
  );
  return { onClose };
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('NewPatientModal', () => {
  it('labels every field and blocks alternate closing while creation is pending', async () => {
    const user = userEvent.setup();
    const request = deferred<Patient>();
    vi.mocked(createPatient).mockReturnValue(request.promise);
    const { onClose } = renderModal();

    const name = screen.getByLabelText(/Nombre completo/i);
    const idNumber = screen.getByLabelText(/Cédula/i);
    const birthDate = screen.getByLabelText(/Fecha de nacimiento/i);
    const gender = screen.getByRole('combobox', { name: /Sexo/i });
    const address = screen.getByLabelText(/Dirección/i);
    const email = screen.getByLabelText(/Correo/i);
    const phone = screen.getByLabelText(/Teléfono/i);
    expect(screen.getByLabelText('Aseguradora')).toBeTruthy();
    expect(screen.getByLabelText('N.º de póliza')).toBeTruthy();
    expect(gender).toBeTruthy();

    await user.type(name, 'María López');
    await user.type(idNumber, '1712345678');
    await user.type(birthDate, '1988-03-15');
    await user.type(address, 'Av. Amazonas N34-120');
    await user.type(email, 'maria@example.com');
    await user.type(phone, '0991234567');
    await user.click(screen.getByRole('button', { name: 'Crear paciente' }));

    await waitFor(() => {
      expect(
        (screen.getByRole('button', { name: 'Cancelar' }) as HTMLButtonElement).disabled,
      ).toBe(true);
    });
    expect(
      (screen.getByRole('button', { name: 'Guardando…' }) as HTMLButtonElement).disabled,
    ).toBe(true);

    await user.keyboard('{Escape}');
    expect(onClose).not.toHaveBeenCalled();
  });

  it('creates a patient without typing an email', async () => {
    const user = userEvent.setup();
    const created = { id: 1 } as Patient;
    vi.mocked(createPatient).mockResolvedValue(created);
    renderModal();

    await user.type(screen.getByLabelText(/Nombre completo/i), 'Juan Pérez');
    await user.type(screen.getByLabelText(/Cédula/i), '1712345678');
    await user.type(screen.getByLabelText(/Fecha de nacimiento/i), '1990-01-01');
    await user.type(screen.getByLabelText(/Dirección/i), 'Av. Amazonas N34-120');
    await user.type(screen.getByLabelText(/Teléfono/i), '0991234567');
    await user.click(screen.getByRole('button', { name: 'Crear paciente' }));

    await waitFor(() => {
      expect(createPatient).toHaveBeenCalled();
    });
    expect(screen.queryByText('Correo no válido')).toBeNull();
  });
});

function renderCreating() {
  const qc = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  const onCreated = vi.fn();
  render(
    <QueryClientProvider client={qc}>
      <NewPatientModal onClose={() => undefined} onCreated={onCreated} />
    </QueryClientProvider>,
  );
  return { onCreated };
}

async function fillRequired(user: ReturnType<typeof userEvent.setup>) {
  await user.type(screen.getByLabelText('Nombre completo'), 'Ana Torres');
  await user.type(screen.getByLabelText('Cédula'), '1712345678');
  await user.type(screen.getByLabelText('Fecha de nacimiento'), '1990-05-11');
  await user.type(screen.getByLabelText('Dirección'), 'Av. Amazonas');
  await user.type(screen.getByLabelText('Teléfono'), '0991234567');
}

function axiosError(over: Record<string, unknown>) {
  return Object.assign(new Error('Request failed'), { isAxiosError: true, ...over });
}

describe('NewPatientModal — never looks frozen', () => {
  it('puts a server error on its own field and says the patient was not saved', async () => {
    const user = userEvent.setup();
    vi.mocked(createPatient).mockRejectedValue(axiosError({
      response: { status: 422, data: { message: 'Ya existe un paciente con ese documento.', errors: { id_number: ['Ya existe un paciente con ese documento.'] } } },
    }) as never);
    renderCreating();

    await fillRequired(user);
    await user.click(screen.getByRole('button', { name: 'Crear paciente' }));

    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByText('No se guardó el paciente')).toBeTruthy();
    // The cédula field itself carries the message, and gets the focus.
    await waitFor(() => expect(screen.getByLabelText('Cédula').getAttribute('aria-invalid')).toBe('true'));
    expect(document.activeElement).toBe(screen.getByLabelText('Cédula'));
    // And the button is usable again.
    expect(screen.getByRole('button', { name: 'Crear paciente' })).toHaveProperty('disabled', false);
  });

  it('explains a timeout instead of staying on «Guardando…»', async () => {
    const user = userEvent.setup();
    vi.mocked(createPatient).mockRejectedValue(axiosError({ code: 'ECONNABORTED' }) as never);
    renderCreating();

    await fillRequired(user);
    await user.click(screen.getByRole('button', { name: 'Crear paciente' }));

    expect(await screen.findByText(/tardó demasiado en responder/)).toBeTruthy();
    expect(screen.queryByText('Guardando…')).toBeNull();
  });

  it('creates the patient with the form values', async () => {
    const user = userEvent.setup();
    vi.mocked(createPatient).mockResolvedValue({ id: 30, name: 'Ana Torres' } as never);
    const { onCreated } = renderCreating();

    await fillRequired(user);
    await user.click(screen.getByRole('button', { name: 'Crear paciente' }));

    await waitFor(() => expect(onCreated).toHaveBeenCalled());
    expect(vi.mocked(createPatient).mock.calls[0][0]).toMatchObject({ name: 'Ana Torres', id_number: '1712345678' });
    // The doctor sees that it worked.
    expect(useUiStore.getState().toasts.map((t) => t.message)).toContain('Paciente creado: Ana Torres');
  });
});

describe('NewPatientModal — impossible birth dates', () => {
  it('says the year is wrong before sending, instead of saving a 939-year-old patient', async () => {
    const user = userEvent.setup();
    renderCreating();

    await fillRequired(user);
    const birth = screen.getByLabelText('Fecha de nacimiento');
    await user.clear(birth);
    await user.type(birth, '1087-05-12');
    await user.click(screen.getByRole('button', { name: 'Crear paciente' }));

    expect(await screen.findByText('La fecha de nacimiento no puede ser anterior a 1900. Revisa el año.')).toBeTruthy();
    expect(createPatient).not.toHaveBeenCalled();
  });
});
