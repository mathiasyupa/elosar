// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/store/auth';
import type { ClinicalReport, DailyReport, PnlReport, Workspace } from '@/api/types';
import { ReportsPage } from './ReportsPage';
import { comparisonWindow } from './PeriodBar';
import { percentChange } from './PanoramaPanel';

vi.mock('@/api/reports', () => ({
  fetchDailyReport: vi.fn(),
  fetchClinicalReport: vi.fn(),
  fetchPnlReport: vi.fn(),
  fetchSettlementReport: vi.fn(() => new Promise(() => undefined)),
  fetchRevenueReport: vi.fn(),
  downloadRdacaa: vi.fn(),
}));
vi.mock('./useChartWidth', () => ({ useChartWidth: () => [{ current: null }, 600] }));

const { fetchDailyReport, fetchClinicalReport, fetchPnlReport } = await import('@/api/reports');

function daily(collected: string): DailyReport {
  return {
    scope: 'own', month: '2026-10', from: '2026-10-01', to: '2026-10-31', label: 'octubre 2026',
    is_full_month: true, is_current_month: true,
    days: [
      { date: '2026-10-01', day: 1, weekday: 4, is_weekend: false, is_future: false, is_today: false, total: collected, billed: '50.00', count: 1 },
      { date: '2026-10-02', day: 2, weekday: 5, is_weekend: false, is_future: false, is_today: true, total: '0.00', billed: '0.00', count: 0 },
    ],
    summary: { collected, active_days: 1, daily_avg: '10.00', best_day: null, projection: '400.00' },
    by_weekday: [],
    payment_methods: [],
    today: { date: '2026-10-02', label: 'hoy', collected: '0', billed: '0', invoices: 0, patients: 0, pending: '0', weekday_avg: '0' },
    comparison: { previous_month: '2026-09', previous_label: 'septiembre', previous_to_date: '0', previous_total: '0', delta: '0', delta_pct: null },
  } as DailyReport;
}

function clinical(attentions: number, newPatients: number): ClinicalReport {
  return {
    period: { from: '2026-10-01', to: '2026-10-31' },
    morbidity: [
      { code: 'J00', description: 'Rinofaringitis aguda', total: 9 },
      { code: 'I10', description: 'Hipertensión esencial', total: 4 },
    ],
    by_system: [],
    attentions: { total: attentions, monthly: [], by_type: [], by_doctor: [] },
    demographics: { by_gender: [], by_age_group: [], by_city: [], new_patients: newPatients, returning_patients: 6 },
    medications: [],
    vaccine_reminders: { count: 0, items: [] },
  };
}

function actAs(permissions: string[]) {
  useAuthStore.setState({
    activeWorkspaceId: 1,
    workspaces: [{ id: 1, name: 'Consultorio', type: 'personal', is_clinic: false, permissions, roles: [] } as unknown as Workspace],
  });
}

function Where() {
  const location = useLocation();
  return <p data-testid="where">{location.search}</p>;
}

function renderReports(path = '/reportes?from=2026-10-01&to=2026-10-31') {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/reportes" element={<><ReportsPage /><Where /></>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.useFakeTimers({ toFake: ['Date'] });
  vi.setSystemTime(new Date(2026, 9, 11, 10, 0));
  vi.mocked(fetchDailyReport).mockImplementation(async (period) =>
    period?.from === '2026-10-01' ? daily('120.00') : daily('100.00'),
  );
  vi.mocked(fetchClinicalReport).mockImplementation(async (params) =>
    params?.from === '2026-10-01' ? clinical(30, 8) : clinical(20, 8),
  );
  vi.mocked(fetchPnlReport).mockResolvedValue({ receivable_total: '75.50' } as PnlReport);
});

afterEach(() => {
  cleanup();
  vi.useRealTimers();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, workspaces: [] });
});

describe('comparisonWindow', () => {
  it('compares a running month with the same stretch of the previous one', () => {
    const w = comparisonWindow({ from: '2026-10-01', to: '2026-10-31' }, new Date(2026, 9, 11));
    expect(w.period).toEqual({ from: '2026-09-01', to: '2026-09-11' });
    expect(w.label).toBe('septiembre a la misma fecha');
  });

  it('compares a closed month with the whole previous month', () => {
    const w = comparisonWindow({ from: '2026-09-01', to: '2026-09-30' }, new Date(2026, 9, 11));
    expect(w.period).toEqual({ from: '2026-08-01', to: '2026-08-31' });
    expect(w.label).toBe('agosto');
  });

  it('compares a week with the seven days before it', () => {
    const w = comparisonWindow({ from: '2026-09-07', to: '2026-09-13' }, new Date(2026, 9, 11));
    expect(w.period).toEqual({ from: '2026-08-31', to: '2026-09-06' });
  });

  it('gives no percentage when there is nothing to compare against', () => {
    expect(percentChange(5, 0)).toBeNull();
    expect(percentChange(30, 20)).toBe(50);
  });

  it('labels a custom window by its dates, without repeating «a la misma fecha»', () => {
    const w = comparisonWindow({ from: '2026-10-06', to: '2026-10-12' }, new Date(2026, 9, 11));
    expect(w.period).toEqual({ from: '2026-09-29', to: '2026-10-04' });
    expect(w.label).toBe('29 sep – 4 oct 2026');
  });
});

describe('Reportes → Panorama', () => {
  it('opens on Panorama with the four figures against the previous period', async () => {
    actAs(['reports.view', 'reports.clinical', 'finance.view']);
    renderReports();

    expect(screen.getByRole('tab', { name: /Panorama/ }).getAttribute('aria-selected')).toBe('true');
    const attentions = (await screen.findByText('Atenciones')).closest('.pano-kpi') as HTMLElement;
    expect(await within(attentions).findByText('30')).toBeTruthy();
    expect(within(attentions).getByText(/\+50 %/)).toBeTruthy();
    expect(within(attentions).getByText(/septiembre a la misma fecha/)).toBeTruthy();

    const collected = screen.getByText('Cobrado').closest('.pano-kpi') as HTMLElement;
    expect(within(collected).getByText(/\+20 %/)).toBeTruthy();
    expect(await screen.findByText(/75[.,]50/)).toBeTruthy();
    expect(screen.getByText(/J00 · Rinofaringitis aguda/)).toBeTruthy();
    // Same stretch of September, not the whole month.
    expect(fetchClinicalReport).toHaveBeenCalledWith({ from: '2026-09-01', to: '2026-09-11' });
  });

  it('does not ask for clinical or finance data the role cannot see', async () => {
    actAs(['reports.view']);
    renderReports();

    expect(await screen.findByText('Cobrado')).toBeTruthy();
    expect(screen.getByText('Facturado')).toBeTruthy();
    expect(screen.queryByText('Atenciones')).toBeNull();
    expect(fetchClinicalReport).not.toHaveBeenCalled();
    expect(fetchPnlReport).not.toHaveBeenCalled();
  });

  it('links each block to the tab with the detail', async () => {
    const user = userEvent.setup();
    actAs(['reports.view', 'reports.clinical']);
    renderReports();

    await user.click(await screen.findByRole('button', { name: /Morbilidad/ }));
    expect(screen.getByTestId('where').textContent).toContain('tab=vigilancia');
  });
});

describe('Reportes → Vigilancia', () => {
  it('splits Morbilidad and Producción, and points to where the call lists went', async () => {
    const user = userEvent.setup();
    actAs(['reports.view', 'reports.clinical']);
    renderReports('/reportes?tab=vigilancia&from=2026-10-01&to=2026-10-31');

    expect(await screen.findByText('Principales patologías (CIE-10)')).toBeTruthy();
    expect(screen.queryByText('Medicamentos más prescritos')).toBeNull();
    expect(screen.getByRole('link', { name: /Seguimiento pendiente/ }).getAttribute('href')).toBe('/pacientes?view=follow-ups');

    await user.click(screen.getByRole('tab', { name: /Producción/ }));
    expect(screen.getByText('Medicamentos más prescritos')).toBeTruthy();
    expect(screen.queryByText('Principales patologías (CIE-10)')).toBeNull();
    expect(screen.getByTestId('where').textContent).toContain('vista=produccion');
  });
});
