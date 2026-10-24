/* @vitest-environment jsdom */

import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { FormProvider, useForm } from 'react-hook-form';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { api } from '@/api/client';
import type { Specialty } from '@/api/types';
import { StepSpecialty } from './StepSpecialty';
import { DoctorPreviewCard } from './DoctorPreviewCard';
import type { RegisterFormValues } from './schema';

const specialties: Specialty[] = [
  { id: 20, name: 'Cardiología' },
  { id: 10, name: 'Neurología' },
];

function PreviewForm() {
  const form = useForm<RegisterFormValues>({ defaultValues: { specialty_ids: [] } });
  const queryClient = new QueryClient();

  return (
    <QueryClientProvider client={queryClient}>
      <FormProvider {...form}>
        <form>
          <label>
            Nombre completo
            <input {...form.register('name')} />
          </label>
          <label>
            Ciudad
            <input {...form.register('city')} />
          </label>
          <label>
            Cédula
            <input {...form.register('id_number')} />
          </label>
          <label>
            Correo
            <input {...form.register('email')} />
          </label>
          <StepSpecialty specialties={specialties} />
        </form>
        <DoctorPreviewCard specialties={specialties} />
      </FormProvider>
    </QueryClientProvider>
  );
}

describe('DoctorPreviewCard', () => {
  it('updates form fields and preserves the specialty selection order', async () => {
    const user = userEvent.setup();
    render(<PreviewForm />);

    await user.type(screen.getByLabelText('Nombre completo'), 'Dra. Ana Torres');
    await user.type(screen.getByLabelText('Ciudad'), 'Quito');
    await user.type(screen.getByLabelText('Cédula'), '0912345678');
    await user.type(screen.getByLabelText('Correo'), 'ana@example.com');
    await user.click(screen.getByRole('checkbox', { name: 'Neurología' }));
    await user.click(screen.getByRole('checkbox', { name: 'Cardiología' }));

    const preview = screen.getByRole('region', { name: 'Vista previa de tu perfil' });
    expect(within(preview).getByText('Dra. Ana Torres')).toBeTruthy();
    expect(within(preview).getByText('Quito')).toBeTruthy();
    expect(within(preview).getByText('0912345678')).toBeTruthy();
    expect(within(preview).getByText('ana@example.com')).toBeTruthy();
    expect(within(preview).getByText('Neurología · Cardiología')).toBeTruthy();
    expect(preview.getAttribute('aria-live')).toBeNull();
  });

  it('keeps public wizard specialty selection within the catalog and never posts a new specialty', async () => {
    const user = userEvent.setup();
    const post = vi.spyOn(api, 'post');
    render(<PreviewForm />);

    await user.click(screen.getByRole('checkbox', { name: 'Cardiología' }));

    expect(screen.queryByText(/No está tu especialidad/i)).toBeNull();
    expect(post).not.toHaveBeenCalledWith('/specialties', expect.anything());
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
  });
});
