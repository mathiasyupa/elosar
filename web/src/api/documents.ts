import { api } from './client';

/** What an uploaded file is, chosen at upload. */
export type DocumentCategory = 'lab' | 'image' | 'prescription' | 'certificate' | 'other';

export interface DocumentItem {
  id: string | number;
  numeric_id: number;
  source: 'attachment' | 'prescription' | 'certificate' | 'study_order';
  type: string;
  title: string | null;
  notes: string | null;
  record_id: number | null;
  record_date: string | null;
  record_reason?: string | null;
  download_url?: string;
  /** Type chosen when the file was uploaded; null on older uploads. */
  category?: DocumentCategory | null;
  /** File extension ('pdf', 'jpg'…), to know whether it can be previewed. */
  extension?: string | null;
  patient: { id: number; name: string; id_number: string | null } | null;
}

/** Workspace-wide document library, newest first; filtered on the client. */
export async function fetchDocuments(): Promise<DocumentItem[]> {
  const { data } = await api.get<{ data: DocumentItem[] }>('/documents');
  return data.data;
}

/** Patient-specific document library. */
export async function fetchPatientDocuments(patientId: number): Promise<DocumentItem[]> {
  const { data } = await api.get<{ data: DocumentItem[] }>(`/patients/${patientId}/documents`);
  return data.data;
}

/** Upload an attachment directly to a patient without requiring a consultation. */
export async function uploadPatientDocument(
  patientId: number,
  file: File,
  title?: string,
  notes?: string,
  category?: DocumentCategory,
): Promise<DocumentItem> {
  const form = new FormData();
  form.append('file', file);
  if (title) form.append('title', title);
  if (notes) form.append('notes', notes);
  if (category) form.append('category', category);

  const { data } = await api.post<{ data: DocumentItem }>(`/patients/${patientId}/documents`, form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data.data;
}

/** Download any document (attachment, prescription PDF, certificate PDF) by its download_url. */
export async function downloadDocumentByUrl(url: string): Promise<Blob> {
  const path = url.replace(/^\/api\/v1(?=\/)/, '');
  const { data } = await api.get(path, { responseType: 'blob' });
  return data as Blob;
}
