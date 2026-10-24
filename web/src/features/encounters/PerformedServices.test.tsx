// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchMedicalServices } from '@/api/catalogs';
import type { MedicalService, RecordServiceInput } from '@/api/types';
import { PerformedServices } from './PerformedServices';

vi.mock('@/api/catalogs', () => ({ fetchMedicalServices: vi.fn() }));

const catalog: MedicalService[] = [
  {
    id: 7,
    name: 'Biometría hemática',
    description: null,
    category: 'laboratory',
    base_price: '17.50',
    iva_rate: '0.00',
    is_active: true,
  },
  {
    id: 9,
    name: 'Curación simple',
    description: null,
    category: 'procedure',
    base_price: '10.00',
    iva_rate: '0.00',
    is_active: true,
  },
];

function renderServices({
  services = [] as RecordServiceInput[],
  showPrices = true,
  onChange = vi.fn(),
} = {}) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={queryClient}>
      <PerformedServices services={services} onChange={onChange} showPrices={showPrices} />
    </QueryClientProvider>,
  );

  return { onChange };
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('PerformedServices', () => {
  it('adds a service from the arancel catalogue, carrying its category', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchMedicalServices).mockResolvedValue(catalog);
    const { onChange } = renderServices();

    await user.type(screen.getByLabelText(/Buscar un servicio realizado/i), 'biometria');
    await user.click(await screen.findByText('Biometría hemática'));

    // The category is what later makes revenue breakdown by Laboratorio possible.
    expect(onChange).toHaveBeenCalledWith([
      expect.objectContaining({
        medical_service_id: 7,
        name: 'Biometría hemática',
        category: 'laboratory',
        unit_price: '17.50',
      }),
    ]);
  });

  it('matches the catalogue without accents', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchMedicalServices).mockResolvedValue(catalog);
    renderServices();

    await user.type(screen.getByLabelText(/Buscar un servicio realizado/i), 'curacion');

    expect(await screen.findByText('Curación simple')).toBeTruthy();
  });

  it('hides every price from someone who may not set one', async () => {
    vi.mocked(fetchMedicalServices).mockResolvedValue(catalog);
    renderServices({
      showPrices: false,
      services: [{ medical_service_id: 9, name: 'Curación simple', category: 'procedure', quantity: 1 }],
    });

    expect(await screen.findByText('Curación simple')).toBeTruthy();
    expect(screen.queryByLabelText(/Precio unitario/i)).toBeNull();
    expect(screen.queryByText(/Total estimado/i)).toBeNull();
    expect(screen.getByText(/Caja pone los precios/i)).toBeTruthy();
  });

  it('shows the price controls to someone who may set them', async () => {
    vi.mocked(fetchMedicalServices).mockResolvedValue(catalog);
    renderServices({
      services: [{ medical_service_id: 7, name: 'Biometría hemática', quantity: 2, unit_price: '17.50' }],
    });

    expect(await screen.findByLabelText(/Precio unitario de Biometría hemática/i)).toBeTruthy();
    expect(screen.getByText(/Total estimado/i)).toBeTruthy();
  });

  it('allows a service that is not in the catalogue, flagging what it costs', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchMedicalServices).mockResolvedValue(catalog);
    const { onChange } = renderServices();

    await user.type(screen.getByLabelText(/Buscar un servicio realizado/i), 'Algo no catalogado');
    await user.click(await screen.findByText(/sin arancel/i));

    // No medical_service_id means no category, and therefore no place in the
    // revenue breakdown — the UI says so rather than hiding it.
    expect(onChange).toHaveBeenCalledWith([
      expect.objectContaining({ medical_service_id: null, name: 'Algo no catalogado', category: null }),
    ]);
  });

  it('removes a declared service', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchMedicalServices).mockResolvedValue(catalog);
    const { onChange } = renderServices({
      services: [{ medical_service_id: 9, name: 'Curación simple', quantity: 1, unit_price: '10.00' }],
    });

    await user.click(await screen.findByRole('button', { name: /Quitar Curación simple/i }));

    expect(onChange).toHaveBeenCalledWith([]);
  });
});
