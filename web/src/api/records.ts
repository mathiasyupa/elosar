import { api } from './client';
import type { MedicalAttachment, MedicalRecord, MedicalRecordInput } from './types';

export async function fetchRecord(id: number): Promise<MedicalRecord> {
  const { data } = await api.get<{ data: MedicalRecord }>(`/records/${id}`);
  return data.data;
}

export async function createRecord(payload: MedicalRecordInput): Promise<MedicalRecord> {
  const { data } = await api.post<{ data: MedicalRecord }>('/records', payload);
  return data.data;
}

/** Doctor completes a triage draft: clinical fields + dx/rx, marks completed. */
export async function completeRecord(
  id: number,
  payload: Omit<MedicalRecordInput, 'patient_id'>,
): Promise<MedicalRecord> {
  const { data } = await api.put<{ data: MedicalRecord }>(`/records/${id}`, payload);
  return data.data;
}

/** Closes the consultation (marks the linked appointment as completed). */
export async function closeRecord(id: number): Promise<MedicalRecord> {
  const { data } = await api.post<{ data: MedicalRecord }>(`/records/${id}/close`);
  return data.data;
}

export async function downloadPrescriptionPdf(id: number): Promise<Blob> {
  const { data } = await api.get(`/records/${id}/prescription`, { responseType: 'blob' });
  return data as Blob;
}

export async function uploadAttachment(
  recordId: number,
  file: File,
  title?: string,
  notes?: string,
): Promise<MedicalAttachment> {
  const form = new FormData();
  form.append('file', file);
  if (title) form.append('title', title);
  if (notes) form.append('notes', notes);
  const { data } = await api.post<MedicalAttachment>(`/records/${recordId}/attachments`, form);
  return data;
}

export async function downloadAttachment(id: number): Promise<Blob> {
  const { data } = await api.get(`/attachments/${id}/download`, { responseType: 'blob' });
  return data as Blob;
}

/** Voids a visit saved by mistake (author or workspace owner, with a reason).
 *  The server keeps it on file, soft-deleted, and reopens or cancels its
 *  appointment; an invoiced visit is refused until the invoice is voided. */
export async function voidRecord(id: number, reason: string): Promise<void> {
  await api.post(`/records/${id}/void`, { reason });
}
