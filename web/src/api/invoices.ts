import { api } from './client';
import type { Invoice, InvoiceInput, Paginated, Payment, PaymentInput, SriSummary } from './types';

/**
 * `sriStatus` accepts a stored state (`pending` / `rejected` / `authorized` /
 * `not_sent`), several comma-separated, or the derived `needs_attention` —
 * everything still unfinished with the SRI, which is the question the control
 * panel actually asks.
 */
export async function fetchInvoices(page = 1, status?: string, sriStatus?: string): Promise<Paginated<Invoice>> {
  const { data } = await api.get<Paginated<Invoice>>('/invoices', {
    params: { page, status: status || undefined, sri_status: sriStatus || undefined },
  });
  return data;
}

/** How many vouchers sit in each SRI state, for the counters in Facturación. */
export async function fetchSriSummary(): Promise<SriSummary> {
  const { data } = await api.get<SriSummary>('/invoices/sri-summary');
  return data;
}

export async function fetchInvoice(id: number): Promise<Invoice> {
  const { data } = await api.get<{ data: Invoice }>(`/invoices/${id}`);
  return data.data;
}

export async function createInvoice(payload: InvoiceInput): Promise<Invoice> {
  const { data } = await api.post<{ data: Invoice }>('/invoices', payload);
  return data.data;
}

export async function voidInvoice(id: number, reason: string): Promise<Invoice> {
  const { data } = await api.post<{ data: Invoice }>(`/invoices/${id}/void`, { reason });
  return data.data;
}

export async function cancelInvoice(id: number, reason: string): Promise<Invoice> {
  const { data } = await api.post<{ data: Invoice }>(`/invoices/${id}/cancel`, { reason });
  return data.data;
}

export async function registerPayment(invoiceId: number, payload: PaymentInput): Promise<Payment> {
  const { data } = await api.post<{ data: Payment }>(`/invoices/${invoiceId}/payments`, payload);
  return data.data;
}

export async function deletePayment(paymentId: number): Promise<void> {
  await api.delete(`/payments/${paymentId}`);
}

/** Absolute URL for the invoice PDF (auth headers are added by callers via blob fetch). */
export async function downloadInvoicePdf(id: number): Promise<Blob> {
  const { data } = await api.get(`/invoices/${id}/pdf`, { responseType: 'blob' });
  return data as Blob;
}

export async function retrySri(id: number): Promise<Invoice> {
  const { data } = await api.post<{ data: Invoice }>(`/invoices/${id}/sri/retry`);
  return data.data;
}
