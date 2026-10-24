import { api } from './client';
import type { ClinicalReport, DailyReport, PnlReport, RevenueReport, SettlementReport } from './types';

/** The window every report panel is driven by (the shared period selector). */
export interface PeriodParams {
  from: string;
  to: string;
}

export async function fetchRevenueReport(months = 6): Promise<RevenueReport> {
  const { data } = await api.get<{ data: RevenueReport }>('/reports/revenue', { params: { months } });
  return data.data;
}

/** Day-level finances for the period (defaults to the current month). */
export async function fetchDailyReport(period?: PeriodParams): Promise<DailyReport> {
  const { data } = await api.get<{ data: DailyReport }>('/reports/daily', { params: period ?? {} });
  return data.data;
}

/** Doctor settlement for the period, aggregated + attention by attention. */
export async function fetchSettlementReport(
  period: PeriodParams,
  doctorId?: number | null,
): Promise<SettlementReport> {
  const { data } = await api.get<{ data: SettlementReport }>('/reports/settlement', {
    params: doctorId ? { ...period, doctor_id: doctorId } : period,
  });
  return data.data;
}

/** The clinic's books for the period (finance.view only). */
export async function fetchPnlReport(period: PeriodParams): Promise<PnlReport> {
  const { data } = await api.get<{ data: PnlReport }>('/reports/pnl', { params: period });
  return data.data;
}

export interface ClinicalReportParams {
  from?: string;
  to?: string;
  doctor_id?: number;
}

export async function fetchClinicalReport(params: ClinicalReportParams = {}): Promise<ClinicalReport> {
  const { data } = await api.get<{ data: ClinicalReport }>('/reports/clinical', { params });
  return data.data;
}

/**
 * Download the MSP RDACAA export (CSV) for the given window and trigger a
 * browser save. The endpoint streams the file with the active workspace's
 * bearer token + X-Workspace-Id (injected by the api client).
 */
export async function downloadRdacaa(params: ClinicalReportParams = {}): Promise<void> {
  const response = await api.get('/reports/rdacaa', { params, responseType: 'blob' });

  const disposition = response.headers['content-disposition'] as string | undefined;
  const match = disposition?.match(/filename="?([^"]+)"?/);
  const filename = match?.[1] ?? `rdacaa_${params.from ?? ''}_${params.to ?? ''}.csv`;

  const url = window.URL.createObjectURL(response.data as Blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  window.URL.revokeObjectURL(url);
}
