import { api } from './client';
import type { CreditNote, Paginated } from './types';

export async function fetchCreditNotes(page = 1): Promise<Paginated<CreditNote>> {
  const { data } = await api.get<Paginated<CreditNote>>('/credit-notes', { params: { page } });
  return data;
}
