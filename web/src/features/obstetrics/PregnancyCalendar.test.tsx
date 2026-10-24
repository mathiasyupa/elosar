// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PregnancyCalendar } from './PregnancyCalendar';
import type { Pregnancy } from '@/api/types';

vi.mock('@/api/obstetrics', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/obstetrics')>()),
  fetchPregnancyTimeline: vi.fn(),
}));
const { fetchPregnancyTimeline } = await import('@/api/obstetrics');

afterEach(cleanup);

const pregnancy = {
  id: 7, patient_id: 25, status: 'active', status_label: 'En curso',
  edd_date: '2027-01-25', elective_cesarean_date: '2027-01-18',
  gestational_age: { days: 171, weeks: 24, remainder_days: 3, label: '24+3', trimester: 2, is_term: false, is_post_term: false, is_valid: true },
  dating: { source_used: 'lmp' },
} as unknown as Pregnancy;

function renderCalendar() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}><PregnancyCalendar pregnancy={pregnancy} /></QueryClientProvider>);
}

describe('PregnancyCalendar', () => {
  it('places today, every recorded control, the FPC and the FPP on 40 weeks', async () => {
    vi.mocked(fetchPregnancyTimeline).mockResolvedValue([
      { record_id: 1, date: '2026-06-20', weeks: 8, label: '8+5', fundal_height: null, fetal_heart_rate: null, weight: 61, presentation: null },
      { record_id: 2, date: '2026-09-28', weeks: 22, label: '22+5', fundal_height: 21, fetal_heart_rate: 144, weight: 65, presentation: null },
    ]);
    const { container } = renderCalendar();

    expect(await screen.findByText(/2 controles · último 22\+5/)).toBeTruthy();
    expect(container.querySelectorAll('.preg-cal-control')).toHaveLength(2);
    expect(screen.getByRole('img', { name: /Semana 24\+3 de 40/ })).toBeTruthy();
    expect(container.querySelector('.preg-cal-flag.fpc')).not.toBeNull();
    expect(container.querySelector('.preg-cal-flag.fpp')).not.toBeNull();
    expect(screen.getByText(/FPP 25\/01\/2027/)).toBeTruthy();
  });

  it('says so when no control has been recorded yet', async () => {
    vi.mocked(fetchPregnancyTimeline).mockResolvedValue([]);
    renderCalendar();
    expect(await screen.findByText('Sin controles registrados todavía')).toBeTruthy();
  });
});
