import { api } from './client';
import type { Immunization, ImmunizationInput, Vaccine } from './types';

export async function fetchVaccines(): Promise<Vaccine[]> {
  const { data } = await api.get<{ data: Vaccine[] }>('/vaccines');
  return data.data;
}

export async function fetchPatientImmunizations(patientId: number): Promise<Immunization[]> {
  const { data } = await api.get<{ data: Immunization[] }>(`/patients/${patientId}/immunizations`);
  return data.data;
}

export async function recordImmunization(patientId: number, payload: ImmunizationInput): Promise<Immunization> {
  const { data } = await api.post<{ data: Immunization }>(`/patients/${patientId}/immunizations`, payload);
  return data.data;
}

export async function deleteImmunization(id: number): Promise<void> {
  await api.delete(`/immunizations/${id}`);
}
