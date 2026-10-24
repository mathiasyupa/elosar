import { api } from './client';
import { labelFor, GENDER_LABELS, DOCUMENT_TYPE_LABELS } from '@/i18n/labels';
import type { SheetSpec } from '@/lib/excel';
import type {
  AllergyInput,
  FamilyBackgroundInput,
  MedicalRecord,
  Paginated,
  Patient,
  PatientAllergy,
  PatientCelebrations,
  PatientImportResponse,
  PatientImportRow,
  PatientInput,
  PendingFollowUp,
} from './types';

/** "Felicita a tu paciente" milestones for the active workspace. */
export async function fetchPatientCelebrations(): Promise<PatientCelebrations> {
  const { data } = await api.get<{ data: PatientCelebrations }>('/patients/celebrations');
  return data.data;
}

/** Ordered controls with no appointment booked, most overdue first. */
export async function fetchPendingFollowUps(): Promise<{ items: PendingFollowUp[]; overdue: number }> {
  const { data } = await api.get<{ data: PendingFollowUp[]; meta: { overdue: number } }>(
    '/patients/pending-follow-ups',
  );
  return { items: data.data, overdue: data.meta.overdue };
}

export type GreetingType = 'birthday' | 'anniversary' | 'welcome' | 'reactivation';

/** System-send a relationship greeting email to a patient (Reply-To the doctor). */
export async function sendGreetingEmail(patientId: number, type: GreetingType, years?: number): Promise<void> {
  await api.post(`/patients/${patientId}/greeting-email`, { type, ...(years != null ? { years } : {}) });
}

/** Page size for patient pickers (consultation launcher, appointment form, etc.). */
export const PATIENT_PICKER_PAGE_SIZE = 50;

export interface PatientListOptions {
  hasInsurance?: boolean;
  perPage?: number;
  attendedFrom?: string;
  attendedTo?: string;
}

export async function fetchPatients(
  q = '',
  page = 1,
  sort?: 'recent',
  opts?: PatientListOptions,
): Promise<Paginated<Patient>> {
  const { data } = await api.get<Paginated<Patient>>('/patients', {
    params: {
      q: q || undefined,
      page,
      sort,
      per_page: opts?.perPage,
      has_insurance: opts?.hasInsurance ? 1 : undefined,
      attended_from: opts?.attendedFrom,
      attended_to: opts?.attendedTo,
    },
  });
  return data;
}

/** Fetch every patient matching the current search, across all pages. */
export async function fetchAllPatients(q = '', opts?: PatientListOptions): Promise<Patient[]> {
  const first = await fetchPatients(q, 1, undefined, { ...opts, perPage: 200 });
  const all = [...first.data];

  for (let p = 2; p <= first.meta.last_page; p += 1) {
    const page = await fetchPatients(q, p, undefined, { ...opts, perPage: 200 });
    all.push(...page.data);
  }

  return all;
}

/** Build an .xlsx sheet spec from a patient list. */
export function buildPatientExportSheet(patients: Patient[]): SheetSpec {
  return {
    name: 'Pacientes',
    columns: [
      { header: 'Nombre', key: 'name', width: 32 },
      { header: 'Documento', key: 'id_number', width: 18 },
      { header: 'Tipo', key: 'document_type', width: 16 },
      { header: 'País', key: 'document_country', width: 16 },
      { header: 'Fecha de nacimiento', key: 'birth_date', width: 20, format: 'date' },
      { header: 'Edad', key: 'age', width: 8, format: 'int' },
      { header: 'Género', key: 'gender', width: 14 },
      { header: 'Teléfono', key: 'phone', width: 16 },
      { header: 'Email', key: 'email', width: 28 },
      { header: 'Dirección', key: 'address', width: 28 },
      { header: 'Última visita', key: 'last_visit_at', width: 18, format: 'date' },
    ],
    rows: patients.map((p) => ({
      name: p.name,
      id_number: p.id_number ?? '',
      document_type: labelFor(DOCUMENT_TYPE_LABELS, p.document_type),
      document_country: p.document_country ?? '',
      birth_date: p.birth_date,
      age: p.age,
      gender: labelFor(GENDER_LABELS, p.gender),
      phone: p.phone ?? '',
      email: p.email ?? '',
      address: p.address ?? '',
      last_visit_at: p.last_visit_at ?? null,
    })),
  };
}

export async function fetchPatient(id: number): Promise<Patient> {
  const { data } = await api.get<{ data: Patient }>(`/patients/${id}`);
  return data.data;
}

export async function fetchPatientRecords(id: number, page = 1): Promise<Paginated<MedicalRecord>> {
  const { data } = await api.get<Paginated<MedicalRecord>>(`/patients/${id}/records`, {
    params: { page },
  });
  return data;
}

export interface BaselineVitals {
  source: 'last_visit' | 'standalone';
  recorded_at: string | null;
  weight_kg: string | null;
  height_cm: string | null;
  head_circumference_cm: string | null;
  blood_pressure_sys: number | null;
  blood_pressure_dia: number | null;
  heart_rate: number | null;
  temperature: string | null;
  oxygen_saturation: string | null;
  respiratory_rate: number | null;
}

export interface PatientVitalObservationInput {
  weight_kg?: string;
  height_cm?: string;
  head_circumference_cm?: string;
  blood_pressure_sys?: number;
  blood_pressure_dia?: number;
  heart_rate?: number;
  temperature?: string;
  oxygen_saturation?: string;
  respiratory_rate?: number;
}

/** Save a nursing measurement without creating an appointment or consultation. */
export async function createPatientVitalObservation(
  patientId: number,
  payload: PatientVitalObservationInput,
): Promise<BaselineVitals> {
  const { data } = await api.post<{ data: BaselineVitals }>(
    `/patients/${patientId}/vital-observations`,
    payload,
  );
  return data.data;
}

export async function fetchBaselineVitals(patientId: number): Promise<BaselineVitals | null> {
  const { data } = await api.get<{ data: BaselineVitals | null }>(
    `/patients/${patientId}/baseline-vitals`,
  );
  return data.data;
}

/** A bounded wait: without one, a dropped connection left «Guardando…» on screen
 *  forever and the doctor read it as the software hanging. */
export const CREATE_PATIENT_TIMEOUT_MS = 20_000;

export async function createPatient(payload: PatientInput): Promise<Patient> {
  const { data } = await api.post<{ data: Patient }>('/patients', payload, { timeout: CREATE_PATIENT_TIMEOUT_MS });
  return data.data;
}

export async function importPatients(
  rows: PatientImportRow[],
  dryRun = false,
): Promise<PatientImportResponse['data']> {
  const { data } = await api.post<PatientImportResponse>('/patients/import', { rows, dry_run: dryRun });
  return data.data;
}

export async function updatePatient(id: number, payload: Partial<PatientInput>): Promise<Patient> {
  const { data } = await api.put<{ data: Patient }>(`/patients/${id}`, payload);
  return data.data;
}

/** A patient as it appears in a deletion preview: just enough to name it. */
export interface DeletionCandidate {
  id: number;
  name: string;
}

/**
 * What removing these patients would do — asked before doing it.
 *
 * `deletable` have no trace of care and would really be removed. `archivable`
 * have a history and would only leave the list: the clinical record carries a
 * retention duty and the server refuses to destroy it.
 */
export async function previewPatientDeletion(
  ids: number[],
): Promise<{ deletable: DeletionCandidate[]; archivable: DeletionCandidate[] }> {
  const { data } = await api.post<{ deletable: DeletionCandidate[]; archivable: DeletionCandidate[] }>(
    '/patients/deletion-preview',
    { ids },
  );
  return data;
}

/** Removes one patient. The server decides between deleting and archiving. */
export async function deletePatient(id: number): Promise<{ outcome: 'deleted' | 'archived' }> {
  const { data } = await api.delete<{ outcome: 'deleted' | 'archived' }>(`/patients/${id}`);
  return data;
}

export async function addAllergy(patientId: number, payload: AllergyInput): Promise<PatientAllergy> {
  const { data } = await api.post<PatientAllergy>(`/patients/${patientId}/allergies`, payload);
  return data;
}

export async function deleteAllergy(patientId: number, allergyId: number): Promise<void> {
  await api.delete(`/patients/${patientId}/allergies/${allergyId}`);
}

export async function addFamilyBackground(
  patientId: number,
  payload: FamilyBackgroundInput,
): Promise<void> {
  await api.post(`/patients/${patientId}/family-background`, payload);
}

export async function deleteFamilyBackground(patientId: number, backgroundId: number): Promise<void> {
  await api.delete(`/patients/${patientId}/family-background/${backgroundId}`);
}
