// @vitest-environment jsdom
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { AxiosError } from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { EloContext, EloExamsSuggestion, EloNoteSuggestion, EloRxSuggestion } from '@/api/elo';
import { EloCard } from './EloCard';

vi.mock('@/api/elo', () => ({
  suggestPrescription: vi.fn(),
  suggestNote: vi.fn(),
  suggestExams: vi.fn(),
  logEloEvent: vi.fn(),
}));

const { suggestPrescription, suggestNote, suggestExams, logEloEvent } = await import('@/api/elo');

const CONTEXT: EloContext = {
  age: 30, sex: 'female', weight_kg: 62, reason: 'Dolor de garganta', illness: null,
  diagnoses: [{ code: 'J03.9', description: 'Amigdalitis aguda' }], allergies: ['Penicilina'],
};

const RX: EloRxSuggestion = {
  items: [
    { medication_name: 'Azitromicina', generic_name: 'Azitromicina', concentration: '500 mg', dosage: '500 mg', route: 'Oral',
      frequency: 'cada 24 horas', duration_days: 3, quantity: 3, instructions: 'Con o sin alimentos.', is_controlled: false },
  ],
  reason: 'Alternativa por la alergia a la penicilina.',
  removed_for_allergy: ['Amoxicilina'],
  disclaimer: 'Sugerencia de apoyo: el médico revisa y decide.',
};

const NOTE: EloNoteSuggestion = {
  illness: 'Odinofagia y fiebre de tres días de evolución.',
  diagnoses: [{ code: 'J03.9', description: 'AMIGDALITIS AGUDA, NO ESPECIFICADA', type: 'primary' }],
  dropped_codes: 1,
  reason: 'Fiebre con exudado.',
  disclaimer: '',
};

const EXAMS: EloExamsSuggestion = {
  type: 'laboratory',
  items: [
    { name: 'Biometría hemática completa', study_id: 1, category: 'Hematológico', in_catalog: true },
    { name: 'Antiestreptolisina', study_id: null, category: null, in_catalog: false },
  ],
  clinical_info: 'Amigdalitis aguda febril',
  reason: 'Descartar origen bacteriano.',
  disclaimer: '',
};

function renderCard(props: Partial<Parameters<typeof EloCard>[0]> = {}) {
  const handlers = { onApplyRx: vi.fn(), onApplyNote: vi.fn(), onApplyExams: vi.fn() };
  render(
    <MemoryRouter>
      <QueryClientProvider client={new QueryClient({ defaultOptions: { mutations: { retry: false } } })}>
        <EloCard context={CONTEXT} currentIllness="" hasPrescription={false} {...handlers} {...props} />
      </QueryClientProvider>
    </MemoryRouter>,
  );
  return handlers;
}

beforeEach(() => {
  vi.mocked(suggestPrescription).mockResolvedValue(RX);
  vi.mocked(suggestNote).mockResolvedValue(NOTE);
  vi.mocked(suggestExams).mockResolvedValue(EXAMS);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('Elo — los botones', () => {
  it('waits for something written before offering anything', () => {
    renderCard({ context: { ...CONTEXT, reason: null, diagnoses: [] } });

    expect(screen.getByText('Escribe el motivo o pon un diagnóstico y te ayudo.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Ordenar nota|Sugerir/ })).toBeNull();
  });

  it('offers to tidy the note with text only, and receta and exams once there is a diagnosis', () => {
    renderCard({ context: { ...CONTEXT, diagnoses: [] } });
    expect(screen.getByRole('button', { name: /Ordenar nota/ })).toBeTruthy();
    expect(screen.queryByRole('button', { name: /Sugerir receta/ })).toBeNull();
    cleanup();

    renderCard();
    expect(screen.getByRole('button', { name: /Sugerir receta/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Sugerir exámenes/ })).toBeTruthy();
    expect(suggestPrescription).not.toHaveBeenCalled();
  });
});

describe('Elo — receta', () => {
  it('shows the proposal with its why, and accepting fills the receta', async () => {
    const user = userEvent.setup();
    const { onApplyRx } = renderCard();

    await user.click(screen.getByRole('button', { name: /Sugerir receta/ }));
    expect(suggestPrescription).toHaveBeenCalledWith(CONTEXT);
    expect(await screen.findByText('Azitromicina 500 mg')).toBeTruthy();
    expect(screen.getByText(/Quité Amoxicilina por la alergia registrada/)).toBeTruthy();
    await user.click(screen.getByRole('button', { name: '¿Por qué?' }));
    expect(screen.getByText('Alternativa por la alergia a la penicilina.')).toBeTruthy();

    await user.click(screen.getByRole('button', { name: /Aceptar/ }));
    expect(onApplyRx).toHaveBeenCalledWith(
      [expect.objectContaining({ medication_name: 'Azitromicina', quantity: '3', duration_days: '3' })],
      'replace',
      false,
    );
    expect(logEloEvent).toHaveBeenCalledWith('shown', 1, 'prescription');
    expect(logEloEvent).toHaveBeenCalledWith('accepted', 1, 'prescription');
    expect(screen.getByText('Listo, la receta quedó en la pestaña Receta.')).toBeTruthy();
  });

  it('asks whether to replace or add when there is already a receta', async () => {
    const user = userEvent.setup();
    const { onApplyRx } = renderCard({ hasPrescription: true });

    await user.click(screen.getByRole('button', { name: /Sugerir receta/ }));
    await user.click(await screen.findByRole('button', { name: /Aceptar/ }));
    expect(onApplyRx).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: 'Agregar' }));

    expect(onApplyRx).toHaveBeenCalledWith(expect.any(Array), 'append', false);
  });

  it('sends the doctor to finish a row that came without a quantity', async () => {
    const user = userEvent.setup();
    vi.mocked(suggestPrescription).mockResolvedValue({ ...RX, items: [{ ...RX.items[0], quantity: null }] });
    const { onApplyRx } = renderCard();

    await user.click(screen.getByRole('button', { name: /Sugerir receta/ }));
    await user.click(await screen.findByRole('button', { name: /Aceptar/ }));

    expect(onApplyRx).toHaveBeenCalledWith(expect.any(Array), 'replace', true);
  });

  it('rejecting leaves everything alone', async () => {
    const user = userEvent.setup();
    const { onApplyRx } = renderCard();

    await user.click(screen.getByRole('button', { name: /Sugerir receta/ }));
    await user.click(await screen.findByRole('button', { name: 'Rechazar' }));

    expect(onApplyRx).not.toHaveBeenCalled();
    expect(logEloEvent).toHaveBeenCalledWith('rejected', 1, 'prescription');
  });

  it('says which of the doctor\'s rules shaped the proposal', async () => {
    const user = userEvent.setup();
    vi.mocked(suggestPrescription).mockResolvedValue({ ...RX, rules_applied: ['Prefiero azitromicina en alérgicos'] });
    renderCard();

    await user.click(screen.getByRole('button', { name: /Sugerir receta/ }));

    expect(await screen.findByText('Usé tus reglas: Prefiero azitromicina en alérgicos')).toBeTruthy();
  });
});

describe('Elo — nota y CIE-10', () => {
  it('shows before and after, and accepting hands over the note', async () => {
    const user = userEvent.setup();
    const { onApplyNote } = renderCard({ currentIllness: 'odinofagia fiebre 3d' });

    await user.click(screen.getByRole('button', { name: /Ordenar nota/ }));

    expect(await screen.findByText('Odinofagia y fiebre de tres días de evolución.')).toBeTruthy();
    expect(screen.getByText('odinofagia fiebre 3d')).toBeTruthy();
    expect(screen.getByText('AMIGDALITIS AGUDA, NO ESPECIFICADA')).toBeTruthy();
    expect(screen.getByText('Descarté un código que no está en el CIE-10.')).toBeTruthy();

    await user.click(screen.getByRole('button', { name: /Aceptar/ }));
    await user.click(screen.getByRole('button', { name: 'Reemplazar' }));

    expect(onApplyNote).toHaveBeenCalledWith(NOTE, 'replace', false);
    expect(logEloEvent).toHaveBeenCalledWith('accepted', 2, 'note');
  });
});

describe('Elo — exámenes', () => {
  it('takes the proposal to the order without generating it', async () => {
    const user = userEvent.setup();
    const { onApplyExams } = renderCard();

    await user.click(screen.getByRole('button', { name: /Sugerir exámenes/ }));

    expect(await screen.findByText('Biometría hemática completa')).toBeTruthy();
    expect(screen.getByText('Fuera del catálogo')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: /Llevar a la orden/ }));

    expect(onApplyExams).toHaveBeenCalledWith(EXAMS);
    expect(screen.getByText(/la orden quedó preparada en Órdenes/)).toBeTruthy();
  });
});

describe('Elo — cuando no puede', () => {
  it('a failure is one quiet line, and the doctor can keep working', async () => {
    const user = userEvent.setup();
    vi.mocked(suggestPrescription).mockRejectedValue(new Error('boom'));
    renderCard();

    await user.click(screen.getByRole('button', { name: /Sugerir receta/ }));
    await waitFor(() => expect(screen.getByText('Elo no está disponible ahora. Puedes seguir sin él.')).toBeTruthy());
    expect(screen.getByRole('button', { name: /Sugerir receta/ })).toBeTruthy();
  });

  it('says plainly when Elo is over its usage limit', async () => {
    const user = userEvent.setup();
    vi.mocked(suggestNote).mockRejectedValue(new AxiosError('429', '429', undefined, undefined, { status: 429, data: {} } as never));
    renderCard();

    await user.click(screen.getByRole('button', { name: /Ordenar nota/ }));

    expect(await screen.findByText('Elo llegó a su límite de uso por ahora. Puedes seguir sin él.')).toBeTruthy();
  });

  it('a switched-off agent says so and links to Ajustes', async () => {
    const user = userEvent.setup();
    vi.mocked(suggestExams).mockRejectedValue(
      new AxiosError('409', '409', undefined, undefined, { status: 409, data: {} } as never),
    );
    renderCard();

    await user.click(screen.getByRole('button', { name: /Sugerir exámenes/ }));

    expect(await screen.findByText('Elo de exámenes está apagado.')).toBeTruthy();
    expect(screen.getByRole('link', { name: 'Encender en Ajustes' }).getAttribute('href')).toBe('/ajustes?pane=agents');
  });
});
