import { api } from './client';
import type { ConsciousnessLevel, OxygenTherapy } from '@/features/encounters/scoreMama';
import type {
  ClinicQueue,
  CommissionRule,
  RecordVital,
  MembershipRole,
  RolesCatalog,
  TeamMember,
  SwitchableDoctor,
} from './types';

export async function fetchSwitchableDoctors(): Promise<SwitchableDoctor[]> {
  const { data } = await api.get<{ data: SwitchableDoctor[] }>('/clinic/switchable-doctors');
  return data.data;
}

export async function fetchClinicQueue(date?: string): Promise<ClinicQueue> {
  const { data } = await api.get<{ data: ClinicQueue }>('/clinic/queue', {
    params: date ? { date } : undefined,
  });
  return data.data;
}

export async function fetchClinicTeam(): Promise<TeamMember[]> {
  const { data } = await api.get<{ data: TeamMember[] }>('/clinic/team');
  return data.data;
}

/** Role reference table, derived server-side from the real permission matrix. */
export async function fetchRolesCatalog(): Promise<RolesCatalog> {
  const { data } = await api.get<{ data: RolesCatalog }>('/roles-catalog');
  return data.data;
}

export async function checkInAppointment(id: number): Promise<void> {
  await api.post(`/appointments/${id}/check-in`);
}

export interface NextPatient {
  appointment_id: number;
  patient_id: number;
  status: string;
  draft_record_id: number | null;
}

/** Next ready patient for the doctor (powers "Finalizar y atender al siguiente"). */
export async function fetchNextPatient(doctorId?: number): Promise<NextPatient | null> {
  const { data } = await api.get<{ data: NextPatient | null }>('/clinic/next-patient', {
    params: doctorId ? { doctor_id: doctorId } : undefined,
  });
  return data.data;
}

/** Nurse triage: open (or resume) a draft record for an appointment. */
type TriageVital = Pick<RecordVital,
  'id' | 'weight_kg' | 'height_cm' | 'blood_pressure_sys' | 'blood_pressure_dia'
  | 'heart_rate' | 'temperature' | 'oxygen_saturation' | 'respiratory_rate'
  | 'oxygen_therapy' | 'consciousness_level' | 'urine_output_ml_h'
  | 'urine_output_not_quantifiable' | 'recorded_at'
>;

export interface TriageDraft {
  id: number;
  appointment_id: number | null;
  patient_id: number;
  status: 'draft' | 'vitals_done';
  patient: { id: number; name: string } | null;
  vitals: TriageVital[];
}

export async function startTriageDraft(appointmentId: number): Promise<TriageDraft> {
  const { data } = await api.post<{ data: TriageDraft }>('/records/draft', {
    appointment_id: appointmentId,
  });
  return data.data;
}

export interface TriageVitals {
  weight_kg?: string;
  height_cm?: string;
  blood_pressure_sys?: number;
  blood_pressure_dia?: number;
  heart_rate?: number;
  temperature?: string;
  oxygen_saturation?: number;
  respiratory_rate?: number;
  /** Score MAMÁ. El puntaje NO viaja: lo calcula el servidor con estos valores. */
  oxygen_therapy?: OxygenTherapy;
  consciousness_level?: ConsciousnessLevel;
  urine_output_ml_h?: number;
  urine_output_not_quantifiable?: boolean;
}

/** Nurse triage: save vitals on the draft, advancing it to vitals_done. */
export async function saveTriageVitals(recordId: number, vitals: TriageVitals): Promise<TriageDraft> {
  const { data } = await api.patch<{ data: TriageDraft }>(`/records/${recordId}/vitals`, vitals);
  return data.data;
}

export interface TeamInvite {
  token: string;
  email: string;
  role: MembershipRole;
  expires_at: string | null;
  redeem_path: string;
}

/** Owner invites a staff member by email + role; returns the shareable token. */
export async function inviteTeamMember(email: string, role: MembershipRole): Promise<TeamInvite> {
  const { data } = await api.post<{ data: TeamInvite }>('/clinic/invite', { email, role });
  return data.data;
}

/** Owner changes a member's role or activates/deactivates them. */
export async function updateTeamMember(
  memberId: number,
  payload: {
    roles?: MembershipRole[];
    status?: 'active' | 'inactive';
    commission_pct?: number | null;
  },
): Promise<TeamMember> {
  const { data } = await api.put<{ data: TeamMember }>(`/clinic/team/${memberId}`, payload);
  return data.data;
}

/** Owner reads a member doctor's per-service commission overrides. */
export async function fetchCommissionRules(memberId: number): Promise<CommissionRule[]> {
  const { data } = await api.get<{ data: CommissionRule[] }>(`/clinic/team/${memberId}/commission-rules`);
  return data.data;
}

/** Owner replaces the whole per-service commission rule set for a member doctor. */
export async function setCommissionRules(
  memberId: number,
  rules: { medical_service_id: number; rule_type: 'percentage' | 'fixed'; value: number }[],
): Promise<CommissionRule[]> {
  const { data } = await api.put<{ data: CommissionRule[] }>(`/clinic/team/${memberId}/commission-rules`, { rules });
  return data.data;
}
