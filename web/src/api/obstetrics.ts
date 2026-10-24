import { api } from './client';
import type {
  GynecoHistory,
  GynecoHistoryInput,
  PatientAlert,
  PatientAlertSeverity,
  Pregnancy,
  PregnancyClosePayload,
  PregnancyInput,
  PregnancyScreening,
  PregnancyTimelinePoint,
  PrenatalScreening,
  ScreeningInput,
} from './types';

/** La historia gíneco-obstétrica de la paciente. Nunca devuelve 404: una paciente
 *  sin historia registrada es un estado normal, no un recurso ausente. */
export async function fetchGynecoHistory(patientId: number): Promise<GynecoHistory> {
  const { data } = await api.get<{ data: GynecoHistory }>(`/patients/${patientId}/gyneco-history`);
  return data.data;
}

/** Upsert. El servidor escribe columna por columna: mandar solo lo que cambió no
 *  borra el resto, a diferencia del jsonb de antecedentes personales. */
export async function saveGynecoHistory(
  patientId: number,
  input: GynecoHistoryInput,
): Promise<GynecoHistory> {
  const { data } = await api.put<{ data: GynecoHistory }>(
    `/patients/${patientId}/gyneco-history`,
    input,
  );
  return data.data;
}

/** Embarazos de la paciente: el activo primero, después los cerrados por fecha. */
export async function fetchPregnancies(patientId: number): Promise<Pregnancy[]> {
  const { data } = await api.get<{ data: Pregnancy[] }>(`/patients/${patientId}/pregnancies`);
  return data.data;
}

export async function fetchPregnancy(pregnancyId: number): Promise<Pregnancy> {
  const { data } = await api.get<{ data: Pregnancy }>(`/pregnancies/${pregnancyId}`);
  return data.data;
}

/** La FPP no viaja: la calcula el servidor con la FUM y la ecografía. */
export async function createPregnancy(
  patientId: number,
  input: PregnancyInput,
): Promise<Pregnancy> {
  const { data } = await api.post<{ data: Pregnancy }>(
    `/patients/${patientId}/pregnancies`,
    input,
  );
  return data.data;
}

export async function updatePregnancy(
  pregnancyId: number,
  input: PregnancyInput,
): Promise<Pregnancy> {
  const { data } = await api.put<{ data: Pregnancy }>(`/pregnancies/${pregnancyId}`, input);
  return data.data;
}

/** Cerrar con desenlace. Es lo que libera a la paciente para un embarazo nuevo. */
export async function closePregnancy(
  pregnancyId: number,
  payload: PregnancyClosePayload,
): Promise<Pregnancy> {
  const { data } = await api.post<{ data: Pregnancy }>(
    `/pregnancies/${pregnancyId}/close`,
    payload,
  );
  return data.data;
}

/** Solo para corregir un embarazo abierto por error. Con consultas registradas el
 *  servidor responde 422: eso se cierra, no se borra. */
export async function deletePregnancy(pregnancyId: number): Promise<void> {
  await api.delete(`/pregnancies/${pregnancyId}`);
}

/** El calendario completo del MSP con lo registrado mezclado: el carnet sirve para
 *  ver lo que **falta**, así que vuelven también los que nadie ha hecho. */
export async function fetchScreenings(pregnancyId: number): Promise<PrenatalScreening[]> {
  const { data } = await api.get<{ data: PrenatalScreening[] }>(
    `/pregnancies/${pregnancyId}/screenings`,
  );
  return data.data;
}

/** Registra o corrige un tamizaje. El mismo `code` dos veces corrige, no apila. */
export async function saveScreening(
  pregnancyId: number,
  input: ScreeningInput,
): Promise<PregnancyScreening> {
  const { data } = await api.post<{ data: PregnancyScreening }>(
    `/pregnancies/${pregnancyId}/screenings`,
    input,
  );
  return data.data;
}

/** Avisos permanentes del paciente. Solo vuelven los vigentes. */
export async function fetchPatientAlerts(patientId: number): Promise<PatientAlert[]> {
  const { data } = await api.get<{ data: PatientAlert[] }>(`/patients/${patientId}/alerts`);
  return data.data;
}

export async function createPatientAlert(
  patientId: number,
  input: { text: string; severity?: PatientAlertSeverity },
): Promise<PatientAlert> {
  const { data } = await api.post<{ data: PatientAlert }>(`/patients/${patientId}/alerts`, input);
  return data.data;
}

/** Cerrar un aviso lo conserva: quién lo cerró y cuándo es parte del registro. */
export async function resolvePatientAlert(alertId: number): Promise<PatientAlert> {
  const { data } = await api.post<{ data: PatientAlert }>(`/patient-alerts/${alertId}/resolve`);
  return data.data;
}

/** Un punto por control prenatal, para la curva. Sin banda de normalidad: los
 *  percentiles de altura uterina son una tabla publicada y no se dibuja un rango
 *  clínico que no se pueda citar. */
export async function fetchPregnancyTimeline(
  pregnancyId: number,
): Promise<PregnancyTimelinePoint[]> {
  const { data } = await api.get<{ data: PregnancyTimelinePoint[] }>(
    `/pregnancies/${pregnancyId}/timeline`,
  );
  return data.data;
}
