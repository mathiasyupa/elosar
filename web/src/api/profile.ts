import { api } from './client';
import type { DoctorProfile } from './types';

export async function fetchProfile(): Promise<DoctorProfile> {
  const { data } = await api.get<{ data: DoctorProfile }>('/profile');
  return data.data;
}

export interface ProfileUpdate {
  name?: string;
  phone?: string | null;
  city?: string | null;
  email?: string | null;
  medical_license?: string | null;
  acess_code?: string | null;
  senescyt_registration?: string | null;
  honorific?: 'Dr.' | 'Dra.' | null;
  uses_physical_stamp_signature?: boolean;
  /** First id is the primary specialty (printed on prescriptions) — order matters. */
  specialty_ids?: number[];
}

export async function updateProfile(payload: ProfileUpdate): Promise<DoctorProfile> {
  const { data } = await api.put<{ data: DoctorProfile }>('/profile', payload);
  return data.data;
}

export async function uploadSignature(file: File): Promise<DoctorProfile> {
  const form = new FormData();
  form.append('signature', file);
  const { data } = await api.post<{ data: DoctorProfile }>('/profile/signature', form);
  return data.data;
}

export async function deleteSignature(): Promise<DoctorProfile> {
  const { data } = await api.delete<{ data: DoctorProfile }>('/profile/signature');
  return data.data;
}

export async function uploadPhoto(file: File): Promise<DoctorProfile> {
  const form = new FormData();
  form.append('photo', file);
  const { data } = await api.post<{ data: DoctorProfile }>('/profile/photo', form);
  return data.data;
}

export async function deletePhoto(): Promise<DoctorProfile> {
  const { data } = await api.delete<{ data: DoctorProfile }>('/profile/photo');
  return data.data;
}
