import { api } from './client';
import type { Appointment, AppointmentImportResponse, AppointmentImportRow, AppointmentInput } from './types';

export interface AppointmentFilters {
  /** 'YYYY-MM-DD', or 'all' to ignore the date filter. Defaults to today. */
  date?: string;
  from?: string;
  to?: string;
  doctor_id?: number;
  status?: string;
}

export async function fetchAppointments(filters: AppointmentFilters = {}): Promise<Appointment[]> {
  const { data } = await api.get<{ data: Appointment[] }>('/appointments', { params: filters });
  return data.data;
}

export async function fetchAppointment(id: number): Promise<Appointment> {
  const { data } = await api.get<{ data: Appointment }>(`/appointments/${id}`);
  return data.data;
}

export async function createAppointment(payload: AppointmentInput): Promise<Appointment> {
  const { data } = await api.post<{ data: Appointment }>('/appointments', payload);
  return data.data;
}

export async function updateAppointment(
  id: number,
  payload: Partial<AppointmentInput>,
): Promise<Appointment> {
  const { data } = await api.put<{ data: Appointment }>(`/appointments/${id}`, payload);
  return data.data;
}

export async function cancelAppointment(id: number): Promise<Appointment> {
  const { data } = await api.post<{ data: Appointment }>(`/appointments/${id}/cancel`);
  return data.data;
}

export async function completeAppointment(id: number): Promise<Appointment> {
  const { data } = await api.post<{ data: Appointment }>(`/appointments/${id}/complete`);
  return data.data;
}

export async function checkInAppointment(id: number): Promise<Appointment> {
  const { data } = await api.post<{ data: Appointment }>(`/appointments/${id}/check-in`);
  return data.data;
}

/** Record that a WhatsApp reminder was sent (click-to-chat is manual). */
export async function markReminded(id: number): Promise<Appointment> {
  const { data } = await api.post<{ data: Appointment }>(`/appointments/${id}/mark-reminded`);
  return data.data;
}

/** System-send the appointment confirmation email (Reply-To the doctor). */
export async function sendConfirmationEmail(id: number): Promise<Appointment> {
  const { data } = await api.post<{ data: Appointment }>(`/appointments/${id}/send-confirmation-email`);
  return data.data;
}

/** System-send the appointment reminder email now; also marks reminder_sent_at. */
export async function sendReminderEmail(id: number): Promise<Appointment> {
  const { data } = await api.post<{ data: Appointment }>(`/appointments/${id}/send-reminder-email`);
  return data.data;
}

export interface WalkInInput {
  patient_id?: number;
  name?: string;
  id_number?: string;
  doctor_id: number;
  notes?: string;
}

export async function createWalkInAppointment(payload: WalkInInput): Promise<Appointment> {
  const { data } = await api.post<{ data: Appointment }>('/appointments/walk-in', payload);
  return data.data;
}

export async function importAppointments(rows: AppointmentImportRow[]): Promise<AppointmentImportResponse['data']> {
  const { data } = await api.post<AppointmentImportResponse>('/appointments/import', { rows });
  return data.data;
}
