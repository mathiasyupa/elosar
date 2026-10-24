// @vitest-environment jsdom
import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { HonorificPrompt, useHonorificGate } from './HonorificPrompt';

vi.mock('@/api/profile', () => ({ fetchProfile: vi.fn(), updateProfile: vi.fn() }));
const { fetchProfile, updateProfile } = await import('@/api/profile');

function Issue({ onIssued }: { onIssued: () => void }) {
  const ensureHonorific = useHonorificGate();
  return <button onClick={async () => { await ensureHonorific(); onIssued(); }}>Receta PDF</button>;
}

function renderIssue() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const onIssued = vi.fn();
  render(
    <QueryClientProvider client={qc}>
      <Issue onIssued={onIssued} />
      <HonorificPrompt />
    </QueryClientProvider>,
  );
  return onIssued;
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('¿Cómo firmas? (Dr. / Dra.)', () => {
  it('asks before the first document, saves the answer and lets the document go out', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchProfile).mockResolvedValue({ id: 1, name: 'Ana Torres', honorific: null } as never);
    vi.mocked(updateProfile).mockResolvedValue({ id: 1, name: 'Ana Torres', honorific: 'Dra.' } as never);
    const onIssued = renderIssue();

    await user.click(screen.getByRole('button', { name: 'Receta PDF' }));
    expect(await screen.findByText('¿Cómo firmas tus documentos?')).toBeTruthy();
    expect(onIssued).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: /Dra\./ }));

    await waitFor(() => expect(onIssued).toHaveBeenCalledTimes(1));
    expect(updateProfile).toHaveBeenCalledWith({ honorific: 'Dra.' });
    expect(screen.queryByText('¿Cómo firmas tus documentos?')).toBeNull();
  });

  it('never asks again once chosen, from any document', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchProfile).mockResolvedValue({ id: 1, name: 'Pedro Vera', honorific: 'Dr.' } as never);
    const onIssued = renderIssue();

    await user.click(screen.getByRole('button', { name: 'Receta PDF' }));

    await waitFor(() => expect(onIssued).toHaveBeenCalledTimes(1));
    expect(screen.queryByText('¿Cómo firmas tus documentos?')).toBeNull();
  });

  it('never blocks an account without a doctor profile', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchProfile).mockRejectedValue(new Error('403'));
    const onIssued = renderIssue();

    await act(async () => { await user.click(screen.getByRole('button', { name: 'Receta PDF' })); });

    await waitFor(() => expect(onIssued).toHaveBeenCalledTimes(1));
    expect(screen.queryByText('¿Cómo firmas tus documentos?')).toBeNull();
  });
});
