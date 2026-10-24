// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useUiStore } from '@/store/ui';
import { QuickPatientSearch } from './QuickPatientSearch';

vi.mock('@/api/patients', () => ({
  PATIENT_PICKER_PAGE_SIZE: 50,
  fetchPatients: vi.fn().mockResolvedValue({
    data: [],
    meta: { current_page: 1, last_page: 1, total: 0 },
  }),
}));

describe('QuickPatientSearch', () => {
  afterEach(() => {
    cleanup();
    useUiStore.setState({ quickSearchOpen: false });
  });

  it('keeps the search field focused instead of the first action', () => {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    useUiStore.getState().openQuickSearch();

    render(
      <MemoryRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <QueryClientProvider client={queryClient}>
          <QuickPatientSearch />
        </QueryClientProvider>
      </MemoryRouter>,
    );

    expect(document.activeElement).toBe(
      screen.getByPlaceholderText('Buscar por nombre o cédula…'),
    );
  });
});
