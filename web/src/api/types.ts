// TypeScript mirrors of the backend API Resources (app/Http/Resources/*).
// Monetary fields are strings on purpose — they come from bcmath/decimal casts;
// parse them with a decimal-safe helper, never with parseFloat for arithmetic.

export interface Doctor {
  id: number;
  name: string;
  id_number: string | null;
  email: string | null;
  phone: string | null;
  specialty: string | null;
  /**
   * Capacidades clínicas calculadas por el servidor a partir de las
   * especialidades aprobadas del profesional. Nunca se infieren del nombre de
   * una especialidad creada por el usuario.
   */
  clinical_modules?: ClinicalModule[];
  /**
   * Signed URL that EXPIRES (backend: App\Support\PrivateImageUrl, ~10 min).
   * Render it, never persist it: a stored copy rehydrates as a broken image and
   * leaves a capability URL sitting at rest. Re-read it from the API instead.
   */
  photo_url?: string | null;
}

/** Minimal identity returned only to an authenticated member of a clinic. */
export interface SwitchableDoctor {
  name: string;
  email: string;
  /** Short-lived URL; never persist this value. */
  photo_url: string | null;
}

export type ClinicalModule = 'gyneco_obstetrics';

export interface Specialty {
  id: number;
  name: string;
}

export type AccountType = 'doctor' | 'staff';

export type MembershipRole = 'doctor' | 'nurse' | 'receptionist' | 'billing' | 'admin';

/** Permission strings emitted by WorkspacePermissionService (backend matrix). */
export type Permission =
  | 'dashboard.view'
  | 'appointments.view' | 'appointments.manage' | 'appointments.checkin'
  | 'patients.view' | 'patients.manage' | 'patients.purge'
  | 'records.view' | 'records.manage' | 'vitals.create'
  | 'assistant.use'
  | 'billing.view' | 'billing.manage'
  | 'reports.view' | 'reports.clinical'
  /** The clinic's books: expenses, other income and the net profit. */
  | 'finance.view' | 'finance.manage'
  | 'team.view' | 'team.manage'
  | 'settings.view' | 'settings.manage' | 'settings.letterhead';

/** Clinical seats, in the terms the pricing model uses. */
export interface WorkspaceClinicalSeats {
  /** Doctors the plan covers; null means no clinical seat limit. */
  included: number | null;
  active_doctors: number;
  /** Doctors beyond `included`, each billed separately. */
  additional: number;
}

/**
 * The workspace's commercial state.
 *
 * Gate the UI on `can_write`, never on `state`: a trial whose end date has
 * passed is still stored as `trial` until the nightly job runs, while
 * `effective_access` already reports read_only.
 */
export interface WorkspaceEntitlement {
  state: 'trial' | 'active' | 'read_only';
  effective_access: 'full' | 'read_only';
  can_write: boolean;
  plan_code: 'personal' | 'clinic' | 'clinic_unlimited';
  trial_ends_at: string | null;
  access_ends_at: string | null;
  clinical_seats: WorkspaceClinicalSeats;
  /** The same owner runs a consulting room and a clinic. Informational. */
  bundle_eligible: boolean;
  /** Predates the entitlement rollout; left with full access on purpose. */
  is_legacy_review: boolean;
}

export interface Workspace {
  id: number;
  name: string;
  type: 'personal' | 'clinic';
  is_clinic: boolean;
  /** Null only for a workspace the backfill has not reached yet. */
  entitlement: WorkspaceEntitlement | null;
  logo_path: string | null;
  sri_enabled: boolean;
  // Role-aware fields (per authenticated user).
  membership_role: MembershipRole | null;
  /** Every role the user holds here; permissions are their union. */
  roles: MembershipRole[];
  is_owner: boolean;
  permissions: Permission[];
}

export interface AuthUser {
  id: number;
  name: string;
  email: string;
  account_type: AccountType;
}

export interface LegalStatus {
  terms_acceptance_required: boolean;
  terms_version: string;
  /** Operator-created account must replace its one-time password before use. */
  password_change_required?: boolean;
}

export interface StaffProfile {
  id: number;
  name: string;
  phone: string | null;
  job_title: string | null;
}

export type AppointmentStatus =
  | 'scheduled'
  | 'checked_in'
  | 'vitals_done'
  | 'with_doctor'
  | 'completed'
  | 'cancelled'
  | 'no_show';

export type DocumentType = 'cedula' | 'passport' | 'foreign_id' | 'other';

export interface Patient {
  id: number;
  name: string;
  id_number: string | null;
  document_type?: DocumentType | null;
  document_country?: string | null;
  birth_date: string | null;
  age: number | null;
  gender: string | null;
  phone: string | null;
  email: string | null;
  /** Kept for records created before Dirección replaced it on the form. */
  city: string | null;
  address: string | null;
  last_visit_at?: string | null;
  is_first_visit?: boolean | null;
  // Present only on the detailed (show) endpoint:
  civil_status?: string | null;
  blood_type?: string | null;
  emergency_contact_name?: string | null;
  emergency_contact_phone?: string | null;
  insurance_provider?: string | null;
  insurance_policy_number?: string | null;
  personal_background?: Record<string, unknown> | null;
  allergies?: PatientAllergy[];
  family_background?: FamilyBackground[];
  /** Solo los vigentes: un aviso cerrado es historia y deja de gritar. */
  alerts?: PatientAlert[];
  /** El embarazo en curso viaja con la paciente para que la edad gestacional esté
   *  en pantalla donde ella esté, sin una segunda petición. Solo puede haber uno:
   *  lo garantiza un índice único parcial en la base. */
  active_pregnancy?: Pregnancy | null;
}

export interface PatientAllergy {
  id: number;
  allergen_type: string | null;
  allergen_name: string | null;
  severity: string | null;
  notes: string | null;
}

export interface FamilyBackground {
  id: number;
  relative_type: string | null;
  disease: string | null;
  notes: string | null;
}

export interface PatientImportResult {
  status: 'created' | 'error';
  message: string;
  patient_id: number | null;
  line: number;
}

export interface PatientImportResponse {
  data: {
    committed: boolean;
    ready: boolean;
    created: number;
    errors: number;
    rows: PatientImportResult[];
  };
}

export interface PatientImportRow {
  /** Original spreadsheet row number, for reconciliation only; never persisted on Patient. */
  source_line?: number;
  name: string;
  id_number: string;
  document_type?: DocumentType | null;
  document_country?: string | null;
  birth_date: string;
  gender?: string | null;
  address?: string | null;
  email?: string | null;
  phone?: string | null;
}

export interface AppointmentImportResult {
  status: 'created' | 'error';
  message: string;
  appointment_id: number | null;
  line: number;
}

export interface AppointmentImportResponse {
  data: {
    created: number;
    errors: number;
    rows: AppointmentImportResult[];
  };
}

export interface AppointmentImportRow {
  appointment_date: string;
  id_number: string;
  document_type?: DocumentType | null;
  document_country?: string | null;
  status?: string | null;
  service_name?: string | null;
  channel?: string | null;
  notes?: string | null;
}

export interface Appointment {
  id: number;
  appointment_date: string | null;
  status: AppointmentStatus;
  status_label: string;
  type: string | null;
  medical_service_id: number | null;
  service_name: string | null;
  channel: string | null;
  duration_minutes: number | null;
  notes: string | null;
  confirmed_at: string | null;
  reminder_sent_at: string | null;
  whatsapp_reminded_at?: string | null;
  checked_in_at?: string | null;
  doctor_id?: number | null;
  doctor_name?: string | null;
  patient?: Patient;
}

/** One patient inside a "Felicita a tu paciente" milestone bucket. */
export interface CelebrationPatient {
  id: number;
  name: string;
  phone: string | null;
  email: string | null;
  birth_date: string | null;
  age: number | null;
  years?: number;
  first_visit_at?: string;
  registered_at?: string;
  last_visit_at?: string;
  months_since?: number;
  birthday_md?: string;
  /** Re-engage list only: consultations so far, and lifetime collected
   *  (null when the member may not see money, e.g. nursing). */
  visits?: number;
  spent?: string | null;
}

/** A control the doctor ordered that never turned into an appointment. */
export interface PendingFollowUp {
  record_id: number;
  patient_id: number;
  name: string | null;
  id_number: string | null;
  phone: string | null;
  email: string | null;
  doctor: string | null;
  follow_up_date: string;
  /** Clinical reason — null for members without records.view (LOPDP). */
  notes: string | null;
  overdue: boolean;
  /** Days to the control; negative when overdue. */
  days: number;
}

export interface PatientCelebrations {
  birthdays_today: CelebrationPatient[];
  birthdays_week: CelebrationPatient[];
  anniversaries_today: CelebrationPatient[];
  new_patients: CelebrationPatient[];
  reactivate: CelebrationPatient[];
}

export interface RecordVital {
  id: number;
  weight_kg: string | null;
  height_cm: string | null;
  head_circumference_cm?: string | null;
  length_height_measurement_position?: 'recumbent_length' | 'standing_height' | null;
  bmi: string | null;
  blood_pressure_sys: number | null;
  blood_pressure_dia: number | null;
  heart_rate: number | null;
  temperature: string | null;
  oxygen_saturation: string | null;
  respiratory_rate: number | null;
  /** Score MAMÁ. Conjuntos cerrados: el cálculo no reconoce texto libre. */
  oxygen_therapy?: 'ambient_air' | 'nasal_cannula' | 'mask_or_higher' | null;
  consciousness_level?: 'alert' | 'responds_to_voice' | 'responds_to_pain' | 'unresponsive' | null;
  urine_output_ml_h?: string | null;
  /** "Sin sonda / ambulatoria". Puntúa 0, y NO es lo mismo que ausente. */
  urine_output_not_quantifiable?: boolean | null;
  /** Calculado en el servidor. El cliente nunca lo envía. */
  score_mama_total?: number | null;
  score_mama_level?: 'normal' | 'reevaluate' | 'treat_and_refer' | 'emergency' | null;
  score_mama_protocol?: string | null;
  recorded_at: string | null;
}

export interface GrowthAssessment {
  id: number;
  record_vital_id: number | null;
  status: 'calculated' | 'not_calculable';
  reason_code: string | null;
  measured_at: string | null;
  age_days: number | null;
  sex: 'male' | 'female' | null;
  inputs: { weight_kg: string | null; height_cm: string | null; adjusted_height_cm: string | null; head_circumference_cm: string | null; length_height_measurement_position: 'recumbent_length' | 'standing_height' | null };
  indicators: { code: 'weight_for_age' | 'length_height_for_age' | 'head_circumference_for_age'; status: 'calculated' | 'not_calculable'; z_score: string | null; percentile: string | null; reason_code: string | null }[];
  standard: { source: string; version: string; source_url: string; table_checksums: Record<string, string> };
}

export interface RecordDiagnosis {
  id: number;
  cie10_code: string | null;
  cie10_description: string | null;
  type: string | null;
}

export interface RecordPrescription {
  id: number;
  medication_name: string | null;
  generic_name: string | null;
  concentration: string | null;
  dosage: string | null;
  route: string | null;
  frequency: string | null;
  duration_days: number | null;
  quantity: number | null;
  instructions: string | null;
  is_controlled: boolean;
}

export interface MedicalAttachment {
  id: number;
  title: string | null;
  notes: string | null;
}

export type StudyType = 'laboratory' | 'imaging';

/** Whether the requested study came back. Mirrors StudyOrder::RESULT_STATUSES. */
export type StudyResultStatus = 'pending' | 'completed' | 'cancelled';

/** Orderable study catalog entry (lab test / imaging study). */
export interface Study {
  id: number;
  type: StudyType;
  category: string | null;
  name: string;
  code: string | null;
  is_global: boolean;
}

export interface StudyOrderItem {
  id: number;
  study_id: number | null;
  name: string;
  category: string | null;
  notes?: string | null;
}

/** Lab / imaging order ("solicitud de exámenes") issued in a consultation. */
export interface StudyOrder {
  id: number;
  /**
   * Null when the order was issued for the patient without a consultation —
   * a repeat lab slip asked for by phone, or a study ordered ahead of the
   * appointment it will serve.
   */
  medical_record_id: number | null;
  /** Present when the list eager-loads it, so a row can name its visit. */
  medical_record?: {
    id: number;
    created_at: string | null;
    reason_for_consultation: string | null;
  } | null;
  patient_id: number;
  type: StudyType;
  type_label: string;
  priority: string;
  priority_label?: string;
  clinical_info: string | null;
  notes: string | null;
  to_be_performed_at: string | null;
  created_at: string | null;
  /** Whether the study came back. An order used to be fire-and-forget. */
  result_status: StudyResultStatus;
  result_status_label: string;
  result_received_at: string | null;
  result_notes: string | null;
  /** Documents filed as the answer to this order. */
  results?: {
    id: number;
    title: string | null;
    file_name: string | null;
    created_at: string | null;
    download_url: string;
  }[];
  items?: StudyOrderItem[];
}

export interface StudyOrderItemInput {
  study_id?: number | null;
  name: string;
  category?: string | null;
  notes?: string | null;
}

/** Vaccine catalog row (PAI scheme) — defines the columns of the card. */
export interface Vaccine {
  id: number;
  code: string | null;
  name: string;
  category: string | null;
  max_doses: number;
  max_boosters: number;
}

export type DoseLabel = 'dose_1' | 'dose_2' | 'dose_3' | 'booster_1' | 'booster_2' | 'booster_3' | 'single';

/** A single administered dose recorded on a patient's vaccination card. */
export interface Immunization {
  id: number;
  patient_id: number;
  vaccine_id: number | null;
  vaccine_name: string;
  dose_label: DoseLabel;
  dose_label_text: string;
  administered_at: string | null;
  lot: string | null;
  notes: string | null;
  reminder_at: string | null;
}

export interface ImmunizationInput {
  vaccine_id?: number | null;
  vaccine_name: string;
  dose_label: DoseLabel;
  administered_at: string;
  lot?: string | null;
  notes?: string | null;
  reminder_at?: string | null;
}

export interface StudyOrderInput {
  type: StudyType;
  priority?: string;
  clinical_info?: string | null;
  notes?: string | null;
  to_be_performed_at?: string | null;
  items: StudyOrderItemInput[];
}

export type RecordStatus = 'draft' | 'vitals_done' | 'in_progress' | 'completed';

export interface MedicalRecord {
  id: number;
  appointment_id: number | null;
  patient_id: number;
  status?: RecordStatus;
  triaged_at?: string | null;
  created_at: string | null;
  reason_for_consultation: string | null;
  consultation_type?: string | null;
  professional_title?: string | null;
  structured_exam_template_version?: number | null;
  structured_exam?: Record<string, string> | null;
  current_illness: string | null;
  evolution?: string | null;
  physical_examination: string | null;
  review_of_systems: string | null;
  pain_scale: number | null;
  diagnosis: string | null;
  treatment: string | null;
  recommendations?: string | null;
  alarm_signs?: string | null;
  controlled_prescription_no?: string | null;
  follow_up_date: string | null;
  follow_up_notes: string | null;
  patient?: Patient;
  vitals?: RecordVital[];
  growth_assessments?: GrowthAssessment[];
  diagnoses?: RecordDiagnosis[];
  prescriptions?: RecordPrescription[];
  services?: RecordService[];
  attachments?: MedicalAttachment[];
  study_orders?: StudyOrder[];
}

export interface InvoiceItem {
  id: number;
  description: string | null;
  quantity: string;
  unit_price: string;
  discount_percentage: string;
  discount_amount: string;
  iva_rate: string;
  iva_amount: string;
  line_total: string;
}

export interface Payment {
  id: number;
  amount: string;
  payment_method: string | null;
  payment_date: string | null;
  reference: string | null;
  registered_by?: { id: number; name: string } | null;
}

/** Minimal patient shape for the billing (caja) lookup — no clinical data. */
export interface BillingPatient {
  id: number;
  name: string;
  id_number: string | null;
}

/** A completed appointment pending invoice ("Por cobrar"). */
export interface Billable {
  appointment_id: number;
  date: string | null;
  patient: BillingPatient | null;
  doctor: { id: number; name: string } | null;
  /** Declared by the attending doctor. This is how the services reach caja:
   *  billing roles hold no records.view, so they never see the record itself. */
  services: BillableService[];
}

export interface BillableService {
  medical_service_id: number | null;
  name: string;
  category: string | null;
  quantity: string;
  unit_price: string | null;
}

/** A billable doctor for the cashier's attending-doctor picker. */
export interface BillingDoctor {
  id: number;
  name: string;
  /** Default revenue-share %, "0.00"–"100.00". Null = no default agreed. */
  commission_pct: string | null;
}

/** Curated SRI failure info. The server guarantees no internal detail leaks here. */
export type SriError =
  | { source: 'sri'; code: string; message: string | null; info: string | null }
  | { source: 'internal'; category: 'transport' | 'certificate' | 'config' | 'schema' };

/** One check of the SRI setup diagnosis, in the order the server runs them. */
export interface SriDiagnosticStep {
  key: 'fiscal' | 'certificate' | 'schema' | 'signature' | 'connectivity' | 'probe';
  status: 'ok' | 'fail' | 'skipped';
  detail: string | null;
  error: SriError | null;
}

/** `probed` is false in production, where filing a test voucher is not an option. */
export interface SriDiagnosticResult {
  environment: string;
  probed: boolean;
  steps: SriDiagnosticStep[];
}

/** How many vouchers sit in each SRI state (GET /invoices/sri-summary).
 *  `not_sent` are invoices that never went to the SRI (e-invoicing off).
 *  `pending` and `rejected` exclude annulled invoices — nobody can clear those. */
export interface SriSummary {
  pending: number;
  rejected: number;
  /** Lifetime total. Only grows; the panel shows the month instead. */
  authorized: number;
  not_sent: number;
  /** Authorized within the current month, by the date the SRI replied. */
  authorized_this_month: number;
}

export interface Invoice {
  id: number;
  invoice_number: string | null;
  issued_at: string | null;
  status: string;
  status_label: string;
  subtotal: string;
  iva_amount: string;
  discount_amount: string;
  total: string;
  amount_paid: string;
  amount_due: string;
  notes: string | null;
  buyer_id_type: string | null;
  buyer_id: string | null;
  buyer_name: string | null;
  sri_status: string | null;
  sri_authorization_number: string | null;
  sri_sequence_number: string | null;
  sri_authorization_date: string | null;
  sri_last_attempt_at: string | null;
  sri_error: SriError | null;
  sri_stale_after_seconds: number;
  attending_doctor?: { id: number; name: string } | null;
  /** Per-attention doctor revenue-share % captured at billing time. */
  attending_doctor_commission_pct?: string | null;
  /** Fixed money the doctor gets for this attention (alternative to the %). */
  attending_doctor_commission_amount?: string | null;
  /**
   * What the attending doctor earns from this invoice, computed on money
   * collected. Only present when the viewer is the employed doctor themselves.
   */
  doctor_share?: string | null;
  issued_by?: { id: number; name: string } | null;
  patient?: Patient;
  items?: InvoiceItem[];
  payments?: Payment[];
}

export interface CreditNote {
  id: number;
  credit_note_number: string | null;
  invoice_id: number;
  issued_at: string | null;
  reason: string | null;
  amount: string;
  status: string;
  status_label: string;
  sri_status: string | null;
  invoice?: { id: number; invoice_number: string | null };
}

export interface MedicalService {
  id: number;
  name: string;
  description: string | null;
  category: string | null;
  base_price: string;
  iva_rate: string;
  is_active: boolean;
}

export interface Cie10Code {
  code: string;
  description: string;
  category: string | null;
}

export interface Cie10SearchMeta {
  total: number;
  limit: number;
  truncated: boolean;
}

export interface Cie10SearchResult {
  data: Cie10Code[];
  meta: Cie10SearchMeta;
}

export interface MedicationCatalogItem {
  id: number;
  workspace_id: number | null;
  is_global: boolean;
  name: string;
  concentration: string | null;
  default_dose_quantity: number;
  default_dose_unit: string;
  default_dose_unit_label: string;
  default_frequency: string;
  default_frequency_label: string;
  default_duration_days: number | null;
  route: string;
  route_label: string;
  is_controlled: boolean;
  default_instructions: string | null;
  suggested_dosage: string;
}

// Detailed professional profile (GET /profile).
export interface DoctorProfile extends Doctor {
  /** «Dr.» / «Dra.»; null until chosen (asked before the first document). */
  honorific?: 'Dr.' | 'Dra.' | null;
  uses_physical_stamp_signature: boolean;
  city: string | null;
  medical_license: string | null;
  acess_code: string | null;
  senescyt_registration: string | null;
  specialties?: { id: number; name: string; is_primary?: boolean }[];
  has_signature: boolean;
  /** Signed URL that EXPIRES — see the note on Doctor.photo_url. Use
   *  `has_signature` for "does one exist?", never the URL's truthiness. */
  signature_url: string | null;
  has_photo: boolean;
  photo_url: string | null;
}

export interface PrescriptionSettings {
  header?: string;
  subheader?: string;
  address?: string;
  phone?: string;
  email?: string;
  footer?: string;
  validity_days?: number | null;
}

// Full workspace settings incl. fiscal/SRI data (GET /settings).
export interface WorkspaceSettings {
  id: number;
  name: string;
  type: 'personal' | 'clinic';
  is_clinic: boolean;
  is_owner: boolean;
  logo_path: string | null;
  /** Signed URL that EXPIRES — see the note on Doctor.photo_url. `logo_path`
   *  is the stable identifier; the URL is only good for rendering right now. */
  logo_url: string | null;
  /** Whether documents would print on something other than a blank page —
   *  system letterhead OR a designed sheet (Workspace::hasLetterhead()). */
  has_letterhead: boolean;
  invite_code?: string | null;
  ruc: string | null;
  razon_social: string | null;
  nombre_comercial: string | null;
  dir_matriz: string | null;
  dir_establecimiento: string | null;
  cod_establecimiento: string | null;
  pto_emision: string | null;
  contribuyente_especial: string | null;
  obligado_contabilidad: boolean;
  acess_establishment_code: string | null;
  senescyt_note: string | null;
  sri_environment: string | null;
  sri_enabled: boolean;
  sri_certificate_uploaded: boolean;
  prescription_settings: PrescriptionSettings | null;
}

export type MedicalCertificateType = 'medical' | 'attendance' | 'rest' | 'fitness';

/** Fields of the parameterised certificate (the one an employer / IESS expects). */
export interface MedicalCertificateFields {
  city?: string | null;
  hc_number?: string | null;
  address?: string | null;
  phone?: string | null;
  contingency_type?: string | null;
  company?: string | null;
  activity?: string | null;
  attended_at?: string | null;
  symptoms?: string | null;
  diagnosis?: string | null;
  cie10_codes?: string | null;
  /** Whether the CIE-10 codes print on the certificate (default yes). */
  show_cie10?: boolean;
  treatment?: string | null;
  absolute_rest?: boolean | null;
}

export interface MedicalCertificate extends MedicalCertificateFields {
  id: number;
  patient_id: number;
  medical_record_id: number | null;
  type: MedicalCertificateType;
  type_label: string;
  title: string;
  body: string;
  rest_days: number | null;
  valid_from: string | null;
  valid_until: string | null;
  issued_at: string;
  created_at: string;
  doctor_name?: string | null;
}

export interface MedicalCertificateInput extends MedicalCertificateFields {
  type: MedicalCertificateType;
  title: string;
  body: string;
  rest_days?: number | null;
  valid_from?: string | null;
  valid_until?: string | null;
  issued_at?: string | null;
  medical_record_id?: number | null;
}

export interface TeamMemberToday {
  doctor_id: number;
  name: string;
  appointments: number;
  completed: number;
}

export interface DashboardStats {
  mode?: 'personal';
  appointments_today: number;
  appointments_pending: number;
  appointments_completed: number;
  patients_total: number;
  month_revenue: string;
  last_month_revenue: string;
  pending_collections: string;
  todays_appointments: Appointment[];
  recent_invoices: Invoice[];
  /** Clinic workspaces only: doctors with appointments today. */
  team_today?: TeamMemberToday[];
}

// ---- Clinic operations (queue + team + clinic dashboard) ------------------
export interface QueuePatient {
  id: number;
  name: string;
  id_number: string | null;
  is_first_visit: boolean;
  has_allergies: boolean;
}

export interface QueueRow {
  id: number;
  time: string | null;
  status: AppointmentStatus;
  checked_in_at: string | null;
  draft_record_id?: number | null;
  /** Latest vitals the nurse took, for the waiting room. */
  vitals?: QueueVitals | null;
  doctor: { id: number; name: string } | null;
  patient: QueuePatient | null;
}

export interface QueueVitals {
  blood_pressure_sys: number | null;
  blood_pressure_dia: number | null;
  heart_rate: number | null;
  temperature: string | number | null;
  oxygen_saturation: number | null;
  respiratory_rate: number | null;
  weight_kg: string | number | null;
}

export interface QueueStats {
  waiting: number;
  pending_vitals: number;
  ready: number;
  with_doctor: number;
  completed: number;
  no_show: number;
  scheduled?: number;
  total: number;
}

export interface ClinicQueue {
  date: string;
  stats: QueueStats;
  appointments: QueueRow[];
}

/**
 * Clinic-mode payload returned by /dashboard/stats for clinic workspaces. This
 * mirrors the backend's current shape (queue pipeline + billing snapshot); the
 * dashboard normalizes it into the same view as the personal DashboardStats so
 * the frontend works regardless of the backend deploy state.
 */
export interface ClinicDashboard {
  mode: 'clinic';
  stats: QueueStats;
  queue?: QueueRow[];
  team_today?: TeamMemberToday[];
  alerts?: { allergy_patients: { id: number; name: string }[] };
  billing?: {
    month_revenue: string;
    last_month_revenue: string;
    pending_collections: string;
  };
}


export interface TeamMember {
  id: number;
  name: string;
  email: string | null;
  /** Representative role (badge). See `roles` for the full set. */
  role: MembershipRole;
  /** Every role this member holds; permissions are their union. */
  roles: MembershipRole[];
  status: 'active' | 'inactive';
  /** Doctor revenue-share %, "0.00"–"100.00". Null = no split agreed. */
  commission_pct: string | null;
  doctor_id: number | null;
  account_type: AccountType | null;
  phone: string | null;
  is_owner: boolean;
}

/** One column of the "what does each role do" reference table. */
export interface RoleCapability {
  key: string;
  label: string;
}

/** One row: a role and which capabilities it grants. */
export interface RoleCatalogEntry {
  role: MembershipRole;
  label: string;
  grants: Record<string, boolean>;
}

/** Role reference derived server-side from the real permission matrix. */
export interface RolesCatalog {
  capabilities: RoleCapability[];
  roles: RoleCatalogEntry[];
}

/** Per-service commission override for a doctor (percentage or fixed amount). */
export interface CommissionRule {
  medical_service_id: number;
  service_name: string | null;
  rule_type: 'percentage' | 'fixed';
  value: string;
}

export interface RevenueReport {
  /** 'own' = only this doctor's figures; 'clinic' = the whole clinic's. */
  scope: 'own' | 'clinic';
  monthly: { month: string; label: string; total: string; billed: string }[];
  by_status: { status: string; count: number; total: string }[];
  appointments: { completed: number; cancelled: number; scheduled: number };
  /** Ordered by revenue. `avg` = average price charged, `share` = % of all service revenue. */
  top_services: { description: string; count: number; total: string; avg: string; share: string }[];
  services_total: string;
  by_doctor: {
    doctor_id: number | null;
    name: string;
    count: number;
    total: string;
    /** Doctor revenue-share and split (null when no commission_pct is set). */
    commission_pct: string | null;
    doctor_amount: string | null;
    clinic_amount: string | null;
  }[];
  billed_total: string;
  collected_total: string;
  outstanding_total: string;
}

/** Day-level finances for one month. Mirrors ReportController::daily. */
export interface DailyReport {
  scope: 'own' | 'clinic';
  /** YYYY-MM the period starts in. */
  month: string;
  from: string;
  to: string;
  label: string;
  /** Whether the period is exactly one calendar month (projection + month cut). */
  is_full_month: boolean;
  is_current_month: boolean;
  days: {
    date: string;
    day: number;
    /** 0 = Sunday … 6 = Saturday. */
    weekday: number;
    is_weekend: boolean;
    is_future: boolean;
    is_today: boolean;
    total: string;
    billed: string;
    count: number;
  }[];
  summary: {
    collected: string;
    active_days: number;
    /** Averaged over elapsed days — the pace the month is running at. */
    daily_avg: string;
    best_day: { date: string; total: string } | null;
    /** Straight-line month-end estimate; null for closed months. */
    projection: string | null;
  };
  by_weekday: { weekday: number; label: string; total: string; avg: string }[];
  payment_methods: { method: string; count: number; total: string }[];
  today: {
    date: string;
    label: string;
    collected: string;
    billed: string;
    invoices: number;
    patients: number;
    pending: string;
    /** Average collected on the last 8 same weekdays — today's baseline. */
    weekday_avg: string;
  };
  comparison: {
    previous_month: string;
    previous_label: string;
    previous_to_date: string;
    previous_total: string;
    delta: string;
    delta_pct: string | null;
  };
}

/** The period every panel of the Reportes screen is driven by. */
export interface ReportPeriod {
  from: string;
  to: string;
  label: string;
}

/** One doctor's settlement for the period. */
export interface SettlementDoctorRow {
  doctor_id: number | null;
  name: string;
  /** Attentions (invoices) with money collected in the period. */
  count: number;
  /** Collected and attributed to this doctor. */
  total: string;
  commission_pct: string | null;
  /** Commission before agreed deductions (null = no commission configured). */
  doctor_amount: string | null;
  adjustments: string;
  /** What the doctor actually takes home. */
  doctor_net: string | null;
  clinic_amount: string | null;
}

/** One invoice line: the attention as the clinic audits it. */
export interface SettlementDetailRow {
  invoice_id: number;
  invoice_number: string | null;
  date: string | null;
  doctor_id: number | null;
  doctor_name: string;
  patient_name: string;
  patient_id_number: string | null;
  service: string;
  quantity: number;
  /** List price of the line. */
  price: string;
  /** Part of it actually collected in the period. */
  collected: string;
  doctor_amount: string | null;
  clinic_amount: string | null;
  status: string;
  is_partial: boolean;
}

export interface SettlementReport {
  scope: 'own' | 'clinic';
  period: ReportPeriod;
  /**
   * Whether this caller may read what each doctor is paid. False for the cash
   * desk: collected-per-doctor is their job, compensation is not — the same
   * rule the team roster already applies to `commission_pct`. When false, every
   * compensation figure below arrives null.
   */
  shows_compensation: boolean;
  totals: {
    collected: string;
    doctor_amount: string | null;
    adjustments: string | null;
    doctor_net: string | null;
    clinic_amount: string | null;
    attentions: number;
    invoices: number;
    patients: number;
  };
  by_doctor: SettlementDoctorRow[];
  detail: SettlementDetailRow[];
}

export interface FinanceCategoryTotal {
  category: string;
  count: number;
  total: string;
}

/** cobrado + otros ingresos − comisiones − gastos = utilidad neta. */
export interface PnlReport {
  period: ReportPeriod;
  collected: string;
  billed: string;
  other_income: string;
  income_total: string;
  doctor_commissions: string;
  doctor_adjustments: string;
  expenses: string;
  net_profit: string;
  net_margin_pct: string;
  receivable_total: string;
  expenses_by_category: FinanceCategoryTotal[];
  income_by_category: FinanceCategoryTotal[];
  /** What the clinic sells, by revenue in the period. */
  top_services: {
    total: string;
    rows: { description: string; count: number; total: string; avg: string; share: string }[];
  };
  /**
   * Billed revenue by arancel category — the "¿cuánto entró por laboratorio?"
   * question. Billed, not collected: payments land on the invoice, never on a
   * line. `category: null` = invoiced without picking an arancel.
   *
   * Not to be confused with `income_by_category`, which is hand-typed
   * FinanceEntry "otros ingresos" (room rental and the like).
   */
  services_by_category: {
    total: string;
    rows: { category: string | null; count: number; total: string; share: string }[];
  };
  series: {
    month: string;
    label: string;
    collected: string;
    other_income: string;
    income_total: string;
    commissions: string;
    expenses: string;
    net_profit: string;
  }[];
}

export type FinanceEntryType = 'expense' | 'income' | 'doctor_adjustment';

export interface FinanceEntry {
  id: number;
  type: FinanceEntryType;
  category: string;
  description: string;
  amount: string;
  entry_date: string;
  doctor_id: number | null;
  doctor_name: string | null;
  receipt_number: string | null;
  notes: string | null;
  registered_by: string | null;
  created_at: string | null;
}

export interface FinanceEntryList {
  data: FinanceEntry[];
  meta: {
    count: number;
    totals: Record<FinanceEntryType, string>;
  };
}

/** Clinical / epidemiological report ("Vig. de Salud"). Mirrors ReportController::clinical. */
export interface ClinicalReport {
  period: { from: string; to: string };
  morbidity: { code: string; description: string; total: number }[];
  by_system: { system: string; total: number }[];
  attentions: {
    total: number;
    monthly: { month: string; label: string; total: number }[];
    by_type: { type: string; total: number }[];
    by_doctor: { doctor_id: number | null; name: string; total: number }[];
  };
  demographics: {
    by_gender: { gender: string; total: number }[];
    by_age_group: { group: string; total: number }[];
    by_city: { city: string; total: number }[];
    new_patients: number;
    returning_patients: number;
  };
  medications: { medication: string; total: number; controlled: number }[];
  vaccine_reminders: {
    count: number;
    items: { patient_id: number; patient: string | null; vaccine: string; dose: string; reminder_at: string | null; overdue: boolean }[];
  };
  /** Only supplied when the server authorizes the gyneco-obstetrics module. */
  probable_deliveries?: {
    count: number;
    items: {
      patient_id: number;
      patient: string | null;
      age: number | null;
      phone: string | null;
      /** Nula si el embarazo se datizó solo por ecografía. */
      fum: string | null;
      edd: string;
      weeks: number | null;
      dating: DatingSource | null;
      /** `legacy_exam` = la FUM sigue enterrada en una consulta cerrada y esta
       *  paciente todavía no tiene embarazo registrado. Es lo que permite ofrecer
       *  «abrir embarazo desde esta FUM» sin reescribir historia clínica. */
      origin: 'pregnancy' | 'legacy_exam';
    }[];
  };
}

// ---- Write payloads (mirror the FormRequests) ----------------------------
export interface AppointmentInput {
  patient_id: number;
  doctor_id?: number;
  appointment_date: string;
  type?: string | null;
  medical_service_id?: number | null;
  channel?: string | null;
  duration_minutes?: number | null;
  status?: AppointmentStatus;
  notes?: string | null;
}

export interface PatientInput {
  name: string;
  id_number: string;
  document_type?: DocumentType | null;
  document_country?: string | null;
  birth_date: string;
  gender: string;
  address: string;
  city?: string | null;
  email?: string | null;
  phone: string;
  civil_status?: string | null;
  blood_type?: string | null;
  emergency_contact_name?: string | null;
  emergency_contact_phone?: string | null;
  insurance_provider?: string | null;
  insurance_policy_number?: string | null;
  personal_background?: Record<string, unknown> | null;
}

export interface AllergyInput {
  allergen_name: string;
  allergen_type?: string;
  severity?: string;
  notes?: string | null;
}

export interface FamilyBackgroundInput {
  relative_type: string;
  disease: string;
  notes?: string | null;
}

export type ConsultationType = 'general' | 'gynecological' | 'pediatric' | 'prenatal' | 'preventive';

export interface MedicalRecordInput {
  patient_id: number;
  /** Who the screen believed signed the visit when it opened (waiting-room race guard). */
  expected_created_by?: number | null;
  appointment_id?: number | null;
  reason_for_consultation: string;
  consultation_type?: ConsultationType;
  professional_title?: string;
  structured_exam?: Record<string, string> | null;
  diagnosis: string;
  treatment: string;
  // false autosaves an in-progress draft (record gets a real id, but the
  // visit/appointment are not marked attended yet); absent/true finalizes it.
  finalize?: boolean;
  // Mandatory-closure: completing without a diagnosis flags the override.
  closed_with_override?: boolean;
  completion_note?: string | null;
  current_illness?: string | null;
  evolution?: string | null;
  physical_examination?: string | null;
  review_of_systems?: string | null;
  pain_scale?: number | null;
  recommendations?: string | null;
  alarm_signs?: string | null;
  controlled_prescription_no?: string | null;
  follow_up_date?: string | null;
  follow_up_notes?: string | null;
  vitals?: Partial<{
    weight_kg: string;
    height_cm: string;
    head_circumference_cm: string;
    length_height_measurement_position: 'recumbent_length' | 'standing_height';
    blood_pressure_sys: number;
    blood_pressure_dia: number;
    heart_rate: number;
    temperature: string;
    oxygen_saturation: number;
    respiratory_rate: number;
    // Score MAMA. El puntaje no se envia: lo calcula el servidor con estos.
    oxygen_therapy: string;
    consciousness_level: string;
    urine_output_ml_h: number;
    urine_output_not_quantifiable: boolean;
  }>;
  diagnoses?: { cie10_code: string; cie10_description?: string; type?: string }[];
  prescriptions?: {
    medication_name: string;
    generic_name?: string;
    concentration?: string;
    dosage?: string;
    route?: string;
    frequency?: string;
    duration_days?: number;
    quantity?: number;
    instructions?: string;
    is_controlled?: boolean;
  }[];
  /** What was performed, for the cash desk. `unit_price` is dropped server-side
   *  unless the caller holds billing.manage. */
  services?: RecordServiceInput[];
}

export interface RecordServiceInput {
  medical_service_id?: number | null;
  name: string;
  category?: string | null;
  quantity?: number;
  unit_price?: string | null;
  notes?: string | null;
}

/** A service declared as performed during a visit. Money as strings (bcmath). */
export interface RecordService {
  id: number;
  medical_service_id: number | null;
  name: string;
  category: string | null;
  quantity: string;
  /** Null when declared without a price — the cash desk sets it. */
  unit_price: string | null;
  line_total: string | null;
  notes: string | null;
}

export interface InvoiceItemInput {
  description: string;
  quantity: string;
  unit_price: string;
  iva_rate?: string;
  discount_percentage?: string;
  medical_service_id?: number;
}

export interface InvoiceInput {
  patient_id: number;
  appointment_id?: number | null;
  attending_doctor_id?: number | null;
  /** Doctor revenue-share % for this attention (cashier-set, 0–100). */
  attending_doctor_commission_pct?: string | null;
  /** Fixed money for the doctor this attention. Mutually exclusive with the %. */
  attending_doctor_commission_amount?: string | null;
  issued_at: string;
  notes?: string | null;
  discount_amount?: string;
  buyer_id_type?: string;
  buyer_id?: string | null;
  buyer_name?: string | null;
  buyer_address?: string | null;
  buyer_email?: string | null;
  items: InvoiceItemInput[];
}

export interface PaymentInput {
  amount: string;
  payment_method: 'cash' | 'transfer' | 'card' | 'other';
  payment_date: string;
  reference?: string | null;
  notes?: string | null;
}

// Laravel paginator envelope (resource collections backed by ->paginate()).
export interface Paginated<T> {
  data: T[];
  links: {
    first: string | null;
    last: string | null;
    prev: string | null;
    next: string | null;
  };
  meta: {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
  };
}

// ---------------------------------------------------------------------------
// Gíneco-obstetricia
//
// Espejo de `PregnancyResource` y `GynecoHistoryResource`. La FPP y la edad
// gestacional las calcula el servidor: aquí solo se leen.
// ---------------------------------------------------------------------------

export type DatingSource = 'lmp' | 'ultrasound' | 'manual';

export type PregnancyStatus = 'active' | 'completed' | 'lost' | 'unknown';

export type PregnancyOutcome =
  | 'term_birth'
  | 'preterm_birth'
  | 'cesarean'
  | 'abortion'
  | 'ectopic'
  | 'stillbirth'
  | 'molar';

export interface PregnancyScreening {
  id: number;
  pregnancy_id: number;
  code: string;
  label: string;
  /** Si el MSP lo pide en todo embarazo o solo ante un factor de riesgo. Sin esto,
   *  una casilla vacía se lee como un olvido. */
  universal: boolean;
  result?: string | null;
  result_at?: string | null;
  notes?: string | null;
}

export interface Pregnancy {
  id: number;
  patient_id: number;
  status: PregnancyStatus;
  status_label: string;

  lmp_date?: string | null;
  lmp_is_reliable?: boolean | null;
  ultrasound_date?: string | null;
  ultrasound_weeks?: number | null;
  ultrasound_days?: number | null;
  dating_source: DatingSource;

  /** Derivadas en el servidor; no se envían al guardar. */
  edd_date?: string | null;
  edd_protocol?: string | null;
  /** Fecha probable de cesárea electiva (39 sem., una menos que la FPP). Solo
   *  una estimación de calendario: no sustituye el criterio del médico sobre si
   *  procede cesárea. */
  elective_cesarean_date?: string | null;

  /** Las dos dataciones y su diferencia, para que el médico decida con ambas a la
   *  vista. El producto nunca redata solo: la norma no publica un umbral. */
  dating: {
    edd_by_lmp: string | null;
    edd_by_ultrasound: string | null;
    discrepancy_days: number | null;
    source_used: DatingSource | null;
    missing: string[];
  };

  gestational_age: {
    days: number | null;
    weeks: number | null;
    remainder_days: number | null;
    /** `32+4`. */
    label: string | null;
    trimester: 1 | 2 | 3 | null;
    is_term: boolean;
    is_post_term: boolean;
    is_valid: boolean;
  };

  fetus_count: number;
  risk_notes?: string | null;
  outcome?: PregnancyOutcome | null;
  outcome_label?: string | null;
  outcome_date?: string | null;
  outcome_notes?: string | null;

  screenings?: PregnancyScreening[];

  created_at?: string | null;
  updated_at?: string | null;
}

export interface PregnancyInput {
  lmp_date?: string | null;
  lmp_is_reliable?: boolean | null;
  ultrasound_date?: string | null;
  ultrasound_weeks?: number | null;
  ultrasound_days?: number | null;
  dating_source?: DatingSource;
  fetus_count?: number;
  risk_notes?: string | null;
}

export interface PregnancyClosePayload {
  status?: Exclude<PregnancyStatus, 'active'>;
  outcome: PregnancyOutcome;
  outcome_date: string;
  outcome_notes?: string | null;
}

/** La fórmula obstétrica llega dos veces a propósito: lo que la paciente refiere y
 *  lo que este sistema presenció. No coinciden, y está bien — una es historia y la
 *  otra es registro. El servidor nunca pisa la primera con la segunda. */
export interface ObstetricFormula {
  gestations: number | null;
  term_births: number | null;
  preterm_births: number | null;
  cesareans: number | null;
  abortions: number | null;
  ectopics: number | null;
  stillbirths: number | null;
  living_children: number | null;
}

export interface GynecoHistory {
  id: number | null;
  patient_id: number;

  menarche_age?: number | null;
  cycle_length_days?: number | null;
  cycle_duration_days?: number | null;
  cycle_is_regular?: boolean | null;
  dysmenorrhea?: boolean | null;

  sexual_debut_age?: number | null;
  contraceptive_method?: string | null;
  contraceptive_method_label?: string | null;
  contraceptive_notes?: string | null;

  last_pap_at?: string | null;
  last_pap_result?: string | null;
  last_mammography_at?: string | null;
  last_mammography_result?: string | null;

  declared: ObstetricFormula;
  /** Contados por el servidor sobre los embarazos registrados aquí, así que
   *  siempre son números — un cero es «ninguno», no «no se sabe», al revés que en
   *  `declared`, donde `null` significa que nadie lo preguntó. */
  registered_in_elosar: {
    gestations: number;
    term_births: number;
    preterm_births: number;
    cesareans: number;
    abortions: number;
    ectopics: number;
    stillbirths: number;
    ongoing: number;
  } | null;

  is_menopausal?: boolean | null;
  menopause_at?: string | null;
  notes?: string | null;

  updated_at?: string | null;
}

export type GynecoHistoryInput = Partial<
  Omit<GynecoHistory, 'id' | 'patient_id' | 'declared' | 'registered_in_elosar' | 'updated_at' | 'contraceptive_method_label'>
> &
  Partial<ObstetricFormula>;

/** Una entrada del calendario de tamizajes del MSP, con lo registrado mezclado.
 *  Vuelven **todos**, hechos o no: el carnet sirve para ver lo que falta. */
export interface PrenatalScreening {
  code: string;
  label: string;
  /** Cuándo lo pide la guía, con sus palabras. */
  when: string;
  /** `false` = solo ante un factor de riesgo. Sin esto, una casilla vacía se lee
   *  como un olvido cuando no lo es. */
  universal: boolean;
  result: string | null;
  result_at: string | null;
  notes: string | null;
  recorded: boolean;
}

export interface ScreeningInput {
  code: string;
  result?: string | null;
  result_at?: string | null;
  notes?: string | null;
}

export type PatientAlertSeverity = 'info' | 'warning' | 'critical';

export interface PatientAlert {
  id: number;
  patient_id: number;
  text: string;
  severity: PatientAlertSeverity;
  severity_label: string;
  resolved_at: string | null;
  is_active: boolean;
  created_at: string | null;
}

/** Un control prenatal en la curva del embarazo. La edad gestacional se
 *  recalcula con la datación **vigente**: si el médico redata por ecografía, la
 *  curva entera se mueve con la corrección. */
export interface PregnancyTimelinePoint {
  record_id: number;
  date: string | null;
  weeks: number | null;
  /** `32+4`. */
  label: string | null;
  fundal_height: number | null;
  fetal_heart_rate: number | null;
  weight: number | null;
  /** mmHg, from the control's vitals; null when not taken. */
  bp_systolic?: number | null;
  bp_diastolic?: number | null;
  presentation: string | null;
}
