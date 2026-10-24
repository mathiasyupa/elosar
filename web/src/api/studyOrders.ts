import { api } from './client';
import type { Study, StudyOrder, StudyOrderInput, StudyResultStatus, StudyType } from './types';

/** Catalog of orderable studies (global + workspace custom) for a given type. */
export async function searchStudies(type: StudyType, q = ''): Promise<Study[]> {
  const term = q.trim();
  const { data } = await api.get<{ data: Study[] }>('/studies', {
    params: { type, q: term.length >= 2 ? term : undefined },
  });
  return data.data;
}

export async function createWorkspaceStudy(payload: {
  type: StudyType;
  name: string;
  category?: string | null;
  code?: string | null;
}): Promise<Study> {
  const { data } = await api.post<{ data: Study }>('/studies', payload);
  return data.data;
}

export async function deleteWorkspaceStudy(id: number): Promise<void> {
  await api.delete(`/studies/${id}`);
}

/** Orders attached to a consultation, optionally filtered by type. */
export async function fetchRecordOrders(recordId: number, type?: StudyType): Promise<StudyOrder[]> {
  const { data } = await api.get<{ data: StudyOrder[] }>(`/records/${recordId}/orders`, {
    params: { type: type || undefined },
  });
  return data.data;
}

export async function createStudyOrder(recordId: number, payload: StudyOrderInput): Promise<StudyOrder> {
  const { data } = await api.post<{ data: StudyOrder }>(`/records/${recordId}/orders`, payload);
  return data.data;
}

/**
 * Every order the patient has, with or without a consultation behind it.
 *
 * This is what the Exámenes tab lists: the doctor standing in front of the
 * patient wants to know what is pending for her, not what was asked for in one
 * particular visit.
 */
export async function fetchPatientOrders(patientId: number, type?: StudyType): Promise<StudyOrder[]> {
  const { data } = await api.get<{ data: StudyOrder[] }>(`/patients/${patientId}/orders`, {
    params: { type: type || undefined },
  });
  return data.data;
}

/**
 * Issue an order for the patient. `medical_record_id` is optional: without it
 * the server requires `clinical_info`, since that becomes the only thing on the
 * order saying why the study was requested.
 */
export async function createPatientOrder(
  patientId: number,
  payload: StudyOrderInput & { medical_record_id?: number | null },
): Promise<StudyOrder> {
  const { data } = await api.post<{ data: StudyOrder }>(`/patients/${patientId}/orders`, payload);
  return data.data;
}

export async function downloadStudyOrderPdf(orderId: number): Promise<Blob> {
  const { data } = await api.get(`/study-orders/${orderId}/pdf`, { responseType: 'blob' });
  return data as Blob;
}

export async function deleteStudyOrder(orderId: number): Promise<void> {
  await api.delete(`/study-orders/${orderId}`);
}

/**
 * Mark whether the study came back. Uploading the result document already marks
 * it received — this is for the case where the result arrives on paper, or the
 * study was never performed.
 */
export async function updateStudyOrderResult(
  orderId: number,
  payload: { result_status: StudyResultStatus; result_notes?: string | null },
): Promise<StudyOrder> {
  const { data } = await api.put<{ data: StudyOrder }>(`/study-orders/${orderId}/result`, payload);
  return data.data;
}
