// @vitest-environment jsdom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EloAgent, EloRule } from '@/api/elo';
import { EloAgentsPane } from './EloAgentsPane';

vi.mock('@/api/elo', () => ({
  fetchEloAgents: vi.fn(),
  setEloAgentEnabled: vi.fn(),
  fetchEloRules: vi.fn(),
  addEloRules: vi.fn(),
  updateEloRule: vi.fn(),
  deleteEloRule: vi.fn(),
  teachElo: vi.fn(),
}));

const api = await import('@/api/elo');

const AGENTS: EloAgent[] = [
  { key: 'prescription', name: 'Receta', description: 'Propone una receta.', available: true, enabled: true, rules_count: 1, accepted_rate: 75 },
  { key: 'note', name: 'Nota y CIE-10', description: 'Ordena tu nota.', available: false, enabled: true, rules_count: 0, accepted_rate: null },
];

const RULE: EloRule = { id: 7, agent: 'prescription', text: 'En amigdalitis doy 10 días', enabled: true };

function renderPane() {
  render(
    <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } })}>
      <EloAgentsPane />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(api.fetchEloAgents).mockResolvedValue(AGENTS);
  vi.mocked(api.fetchEloRules).mockResolvedValue([RULE]);
  vi.mocked(api.setEloAgentEnabled).mockResolvedValue();
  vi.mocked(api.deleteEloRule).mockResolvedValue();
  vi.mocked(api.updateEloRule).mockImplementation(async (id, patch) => ({ ...RULE, id, ...patch }));
  vi.mocked(api.addEloRules).mockImplementation(async (_agent, texts) => [RULE, ...texts.map((text, i) => ({ ...RULE, id: 100 + i, text }))]);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

async function openReceta() {
  const user = userEvent.setup();
  renderPane();
  await user.click(await screen.findByRole('button', { name: 'Abrir Receta' }));
  await screen.findByText('En amigdalitis doy 10 días');
  return user;
}

describe('Ajustes → Agentes', () => {
  it('lists the agents with their switch, rules and acceptance; the future ones say Pronto', async () => {
    renderPane();

    expect(await screen.findByText('1 regla · 75 % aceptadas')).toBeTruthy();
    expect(screen.getByText('Pronto')).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Abrir Nota y CIE-10' }) as HTMLButtonElement).disabled).toBe(true);
  });

  it('the switch turns the agent off', async () => {
    const user = userEvent.setup();
    renderPane();

    await user.click(await screen.findByRole('checkbox', { name: 'Receta encendido' }));

    expect(api.setEloAgentEnabled).toHaveBeenCalledWith('prescription', false);
  });

  it('a chat proposal is not saved until the doctor presses Guardar', async () => {
    vi.mocked(api.teachElo).mockResolvedValue({ reply: 'Anotado.', proposed_rules: ['Prefiero ibuprofeno a diclofenaco'] });
    const user = await openReceta();

    await user.type(screen.getByRole('textbox', { name: 'Mensaje para Elo' }), 'casi nunca uso diclofenaco');
    await user.click(screen.getByRole('button', { name: /Enviar/ }));

    expect(await screen.findByText('Prefiero ibuprofeno a diclofenaco')).toBeTruthy();
    expect(api.teachElo).toHaveBeenCalledWith('prescription', 'casi nunca uso diclofenaco', expect.any(Array));
    expect(api.addEloRules).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Guardar' }));

    expect(api.addEloRules).toHaveBeenCalledWith('prescription', ['Prefiero ibuprofeno a diclofenaco']);
    expect(await screen.findByText('Guardada')).toBeTruthy();
  });

  it('discarding a proposal saves nothing', async () => {
    vi.mocked(api.teachElo).mockResolvedValue({ reply: 'Anotado.', proposed_rules: ['No tengo cefalexina'] });
    const user = await openReceta();

    await user.type(screen.getByRole('textbox', { name: 'Mensaje para Elo' }), 'no tengo cefalexina');
    await user.click(screen.getByRole('button', { name: /Enviar/ }));
    await user.click(await screen.findByRole('button', { name: 'Descartar' }));

    expect(screen.getByText('Descartada')).toBeTruthy();
    expect(api.addEloRules).not.toHaveBeenCalled();
  });

  it('edits, pauses and deletes a rule', async () => {
    const user = await openReceta();
    const rules = screen.getByRole('region', { name: 'Lo que sé de ti' });

    await user.click(within(rules).getByRole('button', { name: /Editar: En amigdalitis/ }));
    const input = within(rules).getByRole('textbox', { name: 'Editar regla' });
    await user.clear(input);
    await user.type(input, 'En amigdalitis doy 7 días');
    await user.click(within(rules).getByRole('button', { name: 'Guardar' }));
    expect(api.updateEloRule).toHaveBeenCalledWith(7, { text: 'En amigdalitis doy 7 días' });

    await user.click(within(rules).getByRole('checkbox', { name: /Regla activa/ }));
    expect(api.updateEloRule).toHaveBeenCalledWith(7, { enabled: false });

    await user.click(within(rules).getByRole('button', { name: /Borrar: En amigdalitis/ }));
    await waitFor(() => expect(api.deleteEloRule).toHaveBeenCalledWith(7, expect.anything()));
  });

  it('shows the server\'s reason when a rule is refused', async () => {
    const { AxiosError } = await import('axios');
    vi.mocked(api.addEloRules).mockRejectedValue(
      new AxiosError('422', '422', undefined, undefined, {
        status: 422, data: { errors: { 'texts.0': ['Una regla no lleva cédulas ni datos de un paciente.'] } },
      } as never),
    );
    const user = await openReceta();

    await user.type(screen.getByRole('textbox', { name: 'Nueva regla' }), 'A 1710034065 sin aines');
    await user.click(screen.getByRole('button', { name: /Agregar/ }));

    expect(await screen.findByText('Una regla no lleva cédulas ni datos de un paciente.')).toBeTruthy();
  });
});
