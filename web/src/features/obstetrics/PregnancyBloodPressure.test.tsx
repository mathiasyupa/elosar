// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PregnancyBloodPressure } from './PregnancyBloodPressure';

vi.mock('@/api/obstetrics', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/obstetrics')>()),
  fetchPregnancyTimeline: vi.fn(),
}));
const { fetchPregnancyTimeline } = await import('@/api/obstetrics');

afterEach(cleanup);

const point = (record_id: number, weeks: number, sys: number | null, dia: number | null) => ({
  record_id, date: '2026-09-01', weeks, label: `${weeks}+0`, fundal_height: null,
  fetal_heart_rate: null, weight: null, presentation: null, bp_systolic: sys, bp_diastolic: dia,
});

function renderChart() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(<QueryClientProvider client={qc}><PregnancyBloodPressure pregnancyId={7} /></QueryClientProvider>);
}

describe('PregnancyBloodPressure', () => {
  it('plots each control, gives the last reading and flags 140/90 or more', async () => {
    vi.mocked(fetchPregnancyTimeline).mockResolvedValue([point(1, 12, 110, 70), point(2, 24, 142, 88), point(3, 28, 118, 92)]);
    const { container } = renderChart();

    expect(await screen.findByText('118/92')).toBeTruthy();
    expect(screen.getByText(/2 controles en 140\/90 o más/)).toBeTruthy();
    expect(container.querySelectorAll('.preg-bp-dot.high')).toHaveLength(4);
    expect(container.querySelectorAll('.preg-bp-limit')).toHaveLength(2);
  });

  it('skips controls without a pressure and says when there is none', async () => {
    vi.mocked(fetchPregnancyTimeline).mockResolvedValue([point(1, 12, null, null)]);
    renderChart();
    expect(await screen.findByText('Sin presión arterial registrada en los controles todavía.')).toBeTruthy();
  });
});
