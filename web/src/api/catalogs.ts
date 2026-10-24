import { api } from './client';
import type { Cie10SearchResult, MedicalService, MedicationCatalogItem, Specialty } from './types';

/** Public global specialty catalog (used by doctor self-registration). */
export async function fetchSpecialties(): Promise<Specialty[]> {
  const { data } = await api.get<{ data: Specialty[] }>('/specialties');
  return data.data;
}

/** Add a specialty missing from the catalog in authenticated profile management. */
export async function createSpecialty(name: string): Promise<Specialty> {
  const { data } = await api.post<{ data: Specialty }>('/specialties', { name });
  return data.data;
}

export async function fetchMedicalServices(category?: string): Promise<MedicalService[]> {
  const { data } = await api.get<{ data: MedicalService[] }>('/medical-services', {
    params: { category: category || undefined },
  });
  return data.data;
}

export interface MedicalServiceInput {
  name: string;
  description?: string | null;
  category?: string | null;
  base_price?: number | string | null;
  iva_rate?: number | string | null;
  is_active?: boolean;
}

export async function createMedicalService(payload: MedicalServiceInput): Promise<MedicalService> {
  const { data } = await api.post<{ data: MedicalService }>('/medical-services', payload);
  return data.data;
}

export async function updateMedicalService(id: number, payload: MedicalServiceInput): Promise<MedicalService> {
  const { data } = await api.put<{ data: MedicalService }>(`/medical-services/${id}`, payload);
  return data.data;
}

export async function deleteMedicalService(id: number): Promise<void> {
  await api.delete(`/medical-services/${id}`);
}

export async function fetchCie10Categories(): Promise<string[]> {
  const { data } = await api.get<{ data: string[] }>('/cie10/categories');
  return data.data;
}

export async function searchCie10(
  q = '',
  opts?: { category?: string; limit?: number },
): Promise<Cie10SearchResult> {
  const term = q.trim();
  const category = opts?.category?.trim();
  if (term.length < 2 && !category) {
    return { data: [], meta: { total: 0, limit: opts?.limit ?? 50, truncated: false } };
  }
  const { data } = await api.get<Cie10SearchResult>('/cie10', {
    params: {
      q: term || undefined,
      category: category || undefined,
      limit: opts?.limit ?? 50,
    },
  });
  return {
    data: data.data,
    meta: data.meta ?? { total: data.data.length, limit: opts?.limit ?? 50, truncated: false },
  };
}

export async function searchMedications(q = ''): Promise<MedicationCatalogItem[]> {
  const { data } = await api.get<{ data: MedicationCatalogItem[] }>('/medications', {
    params: q.trim().length >= 2 ? { q } : undefined,
  });
  return data.data;
}

export async function createWorkspaceMedication(payload: {
  name: string;
  concentration?: string | null;
  default_dose_quantity?: number;
  default_dose_unit?: string;
  default_frequency?: string;
  default_duration_days?: number | null;
  route?: string;
  is_controlled?: boolean;
  default_instructions?: string | null;
}): Promise<MedicationCatalogItem> {
  const { data } = await api.post<{ data: MedicationCatalogItem }>('/medications', payload);
  return data.data;
}
