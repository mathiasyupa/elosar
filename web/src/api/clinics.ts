import { api } from './client';
import type { Workspace } from './types';

export async function createClinic(name: string): Promise<Workspace> {
  const { data } = await api.post<{ data: Workspace }>('/clinics', { name });
  return data.data;
}

export async function joinClinic(inviteCode: string): Promise<Workspace> {
  const { data } = await api.post<{ data: Workspace }>('/clinics/join', { invite_code: inviteCode });
  return data.data;
}

export async function fetchInviteCode(clinicId: number): Promise<string> {
  const { data } = await api.get<{ invite_code: string }>(`/clinics/${clinicId}/invite-code`);
  return data.invite_code;
}

export async function regenerateInviteCode(clinicId: number): Promise<string> {
  const { data } = await api.post<{ invite_code: string }>(`/clinics/${clinicId}/regenerate-code`);
  return data.invite_code;
}
