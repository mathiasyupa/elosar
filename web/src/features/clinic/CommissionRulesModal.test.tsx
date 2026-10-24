// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { fetchCommissionRules, setCommissionRules } from '@/api/clinic';
import { fetchMedicalServices } from '@/api/catalogs';
import type { MedicalService } from '@/api/types';
import { CommissionRulesModal } from './CommissionRulesModal';

vi.mock('@/api/clinic', () => ({
  fetchCommissionRules: vi.fn(),
  setCommissionRules: vi.fn(),
}));
vi.mock('@/api/catalogs', () => ({ fetchMedicalServices: vi.fn() }));

function service(id: number, name: string, category: string): MedicalService {
  return {
    id, name, description: null, category,
    base_price: '20.00', iva_rate: '0.00', is_active: true,
  };
}

const catalog = [
  service(1, 'Biometría hemática', 'laboratory'),
  service(2, 'Química sanguínea', 'laboratory'),
  service(3, 'Uroanálisis', 'laboratory'),
  service(4, 'Consulta general', 'general'),
];

function renderModal() {
  vi.mocked(fetchCommissionRules).mockResolvedValue([]);
  vi.mocked(fetchMedicalServices).mockResolvedValue(catalog);
  vi.mocked(setCommissionRules).mockResolvedValue(undefined as never);

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <CommissionRulesModal memberId={5} memberName="Dra. Alvarez" onSaved={vi.fn()} onClose={vi.fn()} />
    </QueryClientProvider>,
  );
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('CommissionRulesModal', () => {
  /**
   * The whole point: a clinic laboratory is run by a technician, so those lines
   * should usually pay no commission — but doing it service by service is
   * fifteen additions per doctor, and that chore never gets done.
   */
  it('sets a whole category in one action', async () => {
    const user = userEvent.setup();
    renderModal();

    await user.selectOptions(await screen.findByLabelText('Categoría'), 'laboratory');
    await user.type(screen.getByLabelText('% (0–100)'), '0');
    await user.click(screen.getByRole('button', { name: /Aplicar a la categoría/i }));

    // All three laboratory services, and nothing else.
    expect(await screen.findByText('Biometría hemática')).toBeTruthy();
    expect(screen.getByText('Química sanguínea')).toBeTruthy();
    expect(screen.getByText('Uroanálisis')).toBeTruthy();
    expect(screen.queryByText('Consulta general')).toBeNull();
  });

  it('saves a zero-valued rule rather than dropping it', async () => {
    const user = userEvent.setup();
    renderModal();

    await user.selectOptions(await screen.findByLabelText('Categoría'), 'laboratory');
    await user.type(screen.getByLabelText('% (0–100)'), '0');
    await user.click(screen.getByRole('button', { name: /Aplicar a la categoría/i }));
    await user.click(screen.getByRole('button', { name: 'Guardar' }));

    // 0 % must travel as a real rule: dropping it would silently fall back to
    // the doctor's global percentage, which is the bug this feature prevents.
    await waitFor(() => {
      expect(setCommissionRules).toHaveBeenCalledWith(5, [
        { medical_service_id: 1, rule_type: 'percentage', value: 0 },
        { medical_service_id: 2, rule_type: 'percentage', value: 0 },
        { medical_service_id: 3, rule_type: 'percentage', value: 0 },
      ]);
    });
  });

  it('only offers categories the clinic actually has', async () => {
    renderModal();

    const select = await screen.findByLabelText('Categoría');
    expect(select.textContent).toContain('Laboratorio');
    expect(select.textContent).toContain('General');
    // No dental aranceles here, so no dental option.
    expect(select.textContent).not.toContain('Odontología');
  });

  it('rejects a percentage above 100', async () => {
    const user = userEvent.setup();
    renderModal();

    await user.selectOptions(await screen.findByLabelText('Categoría'), 'laboratory');
    await user.type(screen.getByLabelText('% (0–100)'), '150');
    await user.click(screen.getByRole('button', { name: /Aplicar a la categoría/i }));

    expect(screen.queryByText('Biometría hemática')).toBeNull();
  });
});
