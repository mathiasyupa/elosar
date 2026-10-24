import { api } from './client';
import type { MedicalCertificate, MedicalCertificateInput, Paginated } from './types';

export async function fetchPatientCertificates(patientId: number): Promise<Paginated<MedicalCertificate>> {
  const { data } = await api.get<Paginated<MedicalCertificate>>(`/patients/${patientId}/certificates`);
  return data;
}

export async function createCertificate(
  patientId: number,
  payload: MedicalCertificateInput,
): Promise<MedicalCertificate> {
  const { data } = await api.post<{ data: MedicalCertificate }>(`/patients/${patientId}/certificates`, payload);
  return data.data;
}

/** Fix a certificate already issued (only its signing doctor may). */
export async function updateCertificate(id: number, payload: MedicalCertificateInput): Promise<MedicalCertificate> {
  const { data } = await api.put<{ data: MedicalCertificate }>(`/certificates/${id}`, payload);
  return data.data;
}

export async function duplicateCertificate(id: number): Promise<MedicalCertificate> {
  const { data } = await api.post<{ data: MedicalCertificate }>(`/certificates/${id}/duplicate`);
  return data.data;
}

export async function previewCertificatePdf(id: number): Promise<Blob> {
  const { data } = await api.get(`/certificates/${id}/preview`, { responseType: 'blob' });
  return data as Blob;
}

/** The certificate being written, as a PDF, without saving it. */
export async function previewCertificateDraft(patientId: number, payload: MedicalCertificateInput): Promise<Blob> {
  const { data } = await api.post(`/patients/${patientId}/certificates/preview`, payload, { responseType: 'blob' });
  return data as Blob;
}

export async function downloadCertificatePdf(id: number): Promise<Blob> {
  const { data } = await api.get(`/certificates/${id}/pdf`, { responseType: 'blob' });
  return data as Blob;
}

/** Contingencia declared on the parameterised certificate (IESS wording). */
export const CONTINGENCY_TYPES = [
  'ENFERMEDAD GENERAL',
  'ACCIDENTE DE TRABAJO',
  'ACCIDENTE COMÚN',
  'ENFERMEDAD PROFESIONAL',
  'MATERNIDAD',
];

export const CERTIFICATE_TYPES = {
  medical: {
    value: 'medical' as const,
    label: 'Certificado médico',
    title: 'Certificado médico',
    body: '',
  },
  attendance: {
    value: 'attendance' as const,
    label: 'Constancia de atención',
    title: 'Constancia de atención médica',
    body: 'Certifico que el/la paciente acudió a consulta médica en esta fecha bajo mi supervisión profesional.',
  },
  rest: {
    value: 'rest' as const,
    label: 'Certificado de reposo',
    title: 'Certificado de reposo médico',
    body: 'Se recomienda reposo médico por el período indicado, por motivo de enfermedad en tratamiento.',
  },
  fitness: {
    value: 'fitness' as const,
    label: 'Aptitud física',
    title: 'Certificado de aptitud física',
    body: 'Tras evaluación clínica, se certifica que el/la paciente se encuentra apto/a para la actividad descrita.',
  },
};

export type CertificateTypeKey = keyof typeof CERTIFICATE_TYPES;
