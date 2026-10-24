import { api } from './client';
import type { FinanceEntry, FinanceEntryList, FinanceEntryType } from './types';

/**
 * Money typed by hand: expenses, income with no invoice behind it, and
 * deductions agreed with a doctor. Gated server-side by finance.view /
 * finance.manage (owner + admin).
 */

export interface FinanceEntryFilters {
  from?: string;
  to?: string;
  type?: FinanceEntryType;
  category?: string;
}

export interface FinanceEntryPayload {
  type: FinanceEntryType;
  category: string;
  description: string;
  amount: string;
  entry_date: string;
  doctor_id?: number | null;
  receipt_number?: string | null;
  notes?: string | null;
}

export async function fetchFinanceEntries(filters: FinanceEntryFilters = {}): Promise<FinanceEntryList> {
  const { data } = await api.get<FinanceEntryList>('/finance-entries', { params: filters });
  return data;
}

export async function createFinanceEntry(payload: FinanceEntryPayload): Promise<FinanceEntry> {
  const { data } = await api.post<{ data: FinanceEntry }>('/finance-entries', payload);
  return data.data;
}

export async function updateFinanceEntry(id: number, payload: FinanceEntryPayload): Promise<FinanceEntry> {
  const { data } = await api.put<{ data: FinanceEntry }>(`/finance-entries/${id}`, payload);
  return data.data;
}

export async function deleteFinanceEntry(id: number): Promise<void> {
  await api.delete(`/finance-entries/${id}`);
}
