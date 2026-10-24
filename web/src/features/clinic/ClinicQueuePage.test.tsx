// @vitest-environment jsdom
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { cleanup } from '@testing-library/react';
import { ClinicQueuePage } from './ClinicQueuePage';

vi.mock('@/api/clinic', () => ({
  fetchClinicQueue: vi.fn(() => new Promise(() => undefined)),
}));

describe('ClinicQueuePage', () => {
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it('uses the Ecuador calendar day for the initial queue date', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-08-15T00:30:00Z'));

    render(
      <QueryClientProvider client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}>
        <ClinicQueuePage />
      </QueryClientProvider>,
    );

    expect((screen.getByLabelText('Fecha de la cola') as HTMLInputElement).value).toBe('2026-08-14');
  });
});
