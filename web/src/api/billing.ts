import { api } from './client';
import type { Billable, BillingDoctor, BillingPatient } from './types';

/** "Por cobrar": completed appointments without an invoice yet. */
export async function fetchBillables(): Promise<Billable[]> {
  const { data } = await api.get<{ data: Billable[] }>('/clinic/billables');
  return data.data;
}

/** Doctors billable in the active workspace + their default commission % (caja picker). */
export async function fetchBillingDoctors(): Promise<BillingDoctor[]> {
  const { data } = await api.get<{ data: BillingDoctor[] }>('/billing/doctors');
  return data.data;
}

/** Billing-scoped patient lookup (name + cédula only; no clinical data). */
export async function lookupBillingPatients(q: string): Promise<BillingPatient[]> {
  if (q.trim().length < 2) return [];
  const { data } = await api.get<{ data: BillingPatient[] }>('/billing/patients', { params: { q } });
  return data.data;
}
