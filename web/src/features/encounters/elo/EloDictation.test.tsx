// @vitest-environment jsdom
import { act, cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EloContext, EloDictationSuggestion } from '@/api/elo';
import { EloDictation, type DictationFields } from './EloDictation';

vi.mock('@/api/elo', () => ({ suggestFromDictation: vi.fn(), logEloEvent: vi.fn() }));

const { suggestFromDictation, logEloEvent } = await import('@/api/elo');

const CONTEXT: EloContext = { age: 34, sex: 'female', weight_kg: null, reason: null, illness: null, diagnoses: [], allergies: [] };
const EMPTY: DictationFields = { reason: '', illness: '', evolution: '', exam: '' };

const HEARD: EloDictationSuggestion = {
  reason: 'Dolor de garganta y fiebre',
  illness: 'Odinofagia de 3 días con fiebre de 38.5 °C, sin tos.',
  evolution: '',
  exam: 'Amígdalas hipertróficas con exudado.',
  diagnoses: [{ code: 'J03.9', description: 'Amigdalitis aguda, no especificada', type: 'primary' }],
  dropped_codes: 0,
  why: 'Fiebre con exudado amigdalino.',
  disclaimer: 'Sugerencia de apoyo: el médico revisa y decide.',
};

/** A stand-in for Chrome's SpeechRecognition the test can speak through. */
class FakeRecognition {
  static last: FakeRecognition | null = null;
  lang = '';
  continuous = false;
  interimResults = false;
  onresult: ((e: unknown) => void) | null = null;
  onerror: ((e: { error: string }) => void) | null = null;
  onend: (() => void) | null = null;
  constructor() {
    FakeRecognition.last = this;
  }
  start() {}
  stop() {
    this.onend?.();
  }
  abort() {}
  say(finals: string[], interim = '') {
    const results = [
      ...finals.map((t) => Object.assign([{ transcript: t }], { isFinal: true })),
      ...(interim ? [Object.assign([{ transcript: interim }], { isFinal: false })] : []),
    ];
    act(() => this.onresult?.({ resultIndex: 0, results }));
  }
}

function renderDictation(current: DictationFields = EMPTY) {
  const onApply = vi.fn();
  render(
    <MemoryRouter>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { mutations: { retry: false } } })}>
        <EloDictation context={CONTEXT} current={current} onApply={onApply} />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  return onApply;
}

beforeEach(() => {
  (window as unknown as { webkitSpeechRecognition: unknown }).webkitSpeechRecognition = FakeRecognition;
  vi.mocked(suggestFromDictation).mockResolvedValue(HEARD);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  delete (window as unknown as { webkitSpeechRecognition?: unknown }).webkitSpeechRecognition;
});

describe('Escuchar con Elo', () => {
  it('does not show without speech recognition in the browser', () => {
    delete (window as unknown as { webkitSpeechRecognition?: unknown }).webkitSpeechRecognition;
    renderDictation();
    expect(screen.queryByRole('button', { name: /Escuchar con Elo/ })).toBeNull();
  });

  it('shows the words as they are said, in Ecuadorian Spanish', async () => {
    const user = userEvent.setup();
    renderDictation();

    await user.click(screen.getByRole('button', { name: /Escuchar con Elo/ }));
    expect(FakeRecognition.last?.lang).toBe('es-EC');
    expect(screen.getByText('Elo está escuchando')).toBeTruthy();

    FakeRecognition.last?.say(['me duele la garganta hace tres días'], 'y tengo fie');
    expect(screen.getByText(/me duele la garganta hace tres días/)).toBeTruthy();
    expect(screen.getByText(/y tengo fie/)).toBeTruthy();
  });

  it('sends only the scrubbed text, then accepting fills every field it heard', async () => {
    const user = userEvent.setup();
    const onApply = renderDictation();

    await user.click(screen.getByRole('button', { name: /Escuchar con Elo/ }));
    FakeRecognition.last?.say(['mi cédula es 1710034065 y me duele la garganta con fiebre']);
    await user.click(screen.getByRole('button', { name: /Detener y ordenar/ }));

    expect(suggestFromDictation).toHaveBeenCalledWith(CONTEXT, 'mi cédula es [número] y me duele la garganta con fiebre');
    expect(await screen.findByText('Así llenaría la atención:')).toBeTruthy();
    expect(screen.getByText('Dolor de garganta y fiebre')).toBeTruthy();
    expect(screen.getByText('Amígdalas hipertróficas con exudado.')).toBeTruthy();
    expect(screen.getByText('J03.9')).toBeTruthy();
    // An empty proposal (evolución) is not shown.
    expect(screen.queryByText('Evolución')).toBeNull();
    expect(logEloEvent).toHaveBeenCalledWith('shown', 4, 'dictation');

    await user.click(screen.getByRole('button', { name: /Aceptar/ }));
    expect(onApply).toHaveBeenCalledWith(HEARD, 'replace', false);
    expect(screen.getByText(/Elo llenó la atención/)).toBeTruthy();
  });

  it('asks to replace or add when the doctor already wrote in a field', async () => {
    const user = userEvent.setup();
    const onApply = renderDictation({ ...EMPTY, reason: 'Control' });

    await user.click(screen.getByRole('button', { name: /Escuchar con Elo/ }));
    FakeRecognition.last?.say(['me duele la garganta con fiebre']);
    await user.click(screen.getByRole('button', { name: /Detener y ordenar/ }));
    await user.click(await screen.findByRole('button', { name: /Aceptar/ }));

    expect(screen.getByText('Ya escribiste en algunos de estos campos.')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Agregar' }));
    expect(onApply).toHaveBeenCalledWith(HEARD, 'append', false);
  });

  it('does not call Elo when nothing was heard', async () => {
    const user = userEvent.setup();
    renderDictation();

    await user.click(screen.getByRole('button', { name: /Escuchar con Elo/ }));
    await user.click(screen.getByRole('button', { name: /Detener y ordenar/ }));

    expect(suggestFromDictation).not.toHaveBeenCalled();
    expect(screen.getByText(/No alcancé a escuchar nada/)).toBeTruthy();
  });

  it('says so plainly when the microphone is blocked', async () => {
    const user = userEvent.setup();
    renderDictation();

    await user.click(screen.getByRole('button', { name: /Escuchar con Elo/ }));
    act(() => FakeRecognition.last?.onerror?.({ error: 'not-allowed' }));
    expect(screen.getByText(/Permite el micrófono/)).toBeTruthy();
  });

  it('is one quiet line when Elo is down', async () => {
    const user = userEvent.setup();
    vi.mocked(suggestFromDictation).mockRejectedValue(new AxiosError('503', '503', undefined, undefined, { status: 503, data: {} } as never));
    renderDictation();

    await user.click(screen.getByRole('button', { name: /Escuchar con Elo/ }));
    FakeRecognition.last?.say(['me duele la garganta con fiebre']);
    await user.click(screen.getByRole('button', { name: /Detener y ordenar/ }));

    expect(await screen.findByText('Elo no está disponible ahora. Puedes seguir sin él.')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Escuchar con Elo/ })).toBeTruthy();
  });
});
