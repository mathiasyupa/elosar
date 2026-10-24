import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react';
import { useHonorificGate } from '@/components/HonorificPrompt';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { createRecord, completeRecord, downloadPrescriptionPdf, fetchRecord } from '@/api/records';
import { downloadBlob } from '@/lib/download';
import {
  fetchBaselineVitals,
  fetchPatientRecords,
  type BaselineVitals,
} from '@/api/patients';
import { fetchNextPatient } from '@/api/clinic';
import { fetchProfile } from '@/api/profile';
import { Cie10DiagnosisPicker, type DiagnosisRow } from '@/features/patients/workspace/Cie10DiagnosisPicker';
import { PatientCertificatesTab } from '@/features/patients/workspace/PatientCertificatesTab';
import {
  MedicationPicker,
  missingDispensingQuantity,
  type PrescriptionRow,
  prescriptionRowToPayload,
} from '@/features/patients/workspace/MedicationPicker';
import {
  StructuredExamFields,
  CONSULTATION_TYPE_LABELS,
} from '@/features/patients/workspace/StructuredExamFields';
import { PatientHistoryPanel } from '@/features/patients/workspace/PatientHistoryPanel';
import { PatientAlertChip } from '@/features/patients/PatientAlerts';
import { PregnancyChip } from '@/features/obstetrics/PregnancyChip';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useClinicalModules, useWorkspaceCapabilities } from '@/lib/capabilities';
import { fetchEloAgents, suggestNote, type EloContext, type EloDictationSuggestion, type EloExamsSuggestion, type EloNoteSuggestion } from '@/api/elo';
import type { OrdersPrefill } from './EncounterOrdersSection';
import { EloCard } from './elo/EloCard';
import { EloDictation } from './elo/EloDictation';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import { formatDate } from '@/i18n/es';
import { ecuadorDateInputValue } from '@/lib/ecuadorDate';
import { usePatientWorkspace } from '@/features/patients/workspace/context';
import { useEncounterGuardStore } from '@/store/encounterGuard';
import { EncounterClosureScreen } from './EncounterClosureScreen';
import { EncounterOrdersSection } from './EncounterOrdersSection';
import { PerformedServices } from './PerformedServices';
import { PreviousVisit } from './PreviousVisit';
import { PediatricGrowthFields } from './PediatricGrowthFields';
import { EMPTY_SCORE_MAMA, ScoreMamaFields, type ScoreMamaCapture } from './ScoreMamaFields';
import { fieldsFromRecord } from './hydrateEncounter';
import { EncounterSummary } from './EncounterSummary';
import { VoidEncounterDialog } from './VoidEncounterDialog';
import {
  ACUTE_VITAL_KEYS,
  EMPTY_VITALS,
  VITAL_FIELDS,
  bmiClassification,
  computeBmi,
  formatVitalHint,
  type VitalKey,
} from './vitals';
import type {
  ConsultationType,
  MedicalRecord,
  MedicalRecordInput,
  RecordServiceInput,
} from '@/api/types';

/** One-tap follow-up intervals — a control only gets recorded if it's fast. */
const FOLLOW_UP_PRESETS = [
  { days: 7, label: '1 sem' },
  { days: 15, label: '15 días' },
  { days: 30, label: '1 mes' },
  { days: 90, label: '3 meses' },
  { days: 180, label: '6 meses' },
];

/** Today + n days as YYYY-MM-DD (local, matching the date input). */
function addDays(days: number): string {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

// Claves estables en vez de índice: un paso se identifica por lo que es, no
// por su posición.
const STEPS = [
  { key: 'evaluation', label: 'Exploración', icon: 'file' },
  { key: 'dx', label: 'Diagnóstico', icon: 'search' },
  { key: 'rx', label: 'Receta', icon: 'pill' },
  { key: 'orders', label: 'Exámenes', icon: 'flask' },
  { key: 'services', label: 'Servicios', icon: 'invoice' },
  { key: 'certificate', label: 'Certificado', icon: 'shield' },
] as const;
type StepKey = (typeof STEPS)[number]['key'];

/** Quiet time after the last keystroke before the atención autosaves. An
 *  object, not a constant, so tests can shorten it. */
export const encounterTiming = { autosaveDelayMs: 2500 };

const GYNECO_OBSTETRICS_TYPES: ConsultationType[] = ['gynecological', 'prenatal'];

type SaveMode = 'manual' | 'auto';

/** Elo's text into a field: replace it, or add it under what the doctor wrote. */
function mergeText(prev: string, next: string, mode: 'replace' | 'append'): string {
  return mode === 'append' && prev.trim() ? `${prev.trim()}\n\n${next}` : next;
}

function serverMessage(error: unknown): string | undefined {
  return (isAxiosError(error) && (error.response?.data as { message?: string })?.message) || undefined;
}

function clockTime(date: Date): string {
  return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

/**
 * The "Atención" section of the expediente, at `/pacientes/:patientId/atencion`.
 *
 * It works the way doctors already work in Orpheus:
 * - **Guardar registers the visit.** The first save creates the record as
 *   completed and the server opens its attended appointment; every later save
 *   updates that same record. There is no separate "finalizar" gate.
 * - **Leaving does not lose it.** `PatientWorkspaceLayout` keeps this screen
 *   mounted while the doctor visits other sections of the same patient, and
 *   once the visit exists every change autosaves a moment later and flushes
 *   before the screen goes away.
 * - **It can be resumed.** After the first save the URL carries `?record=`,
 *   and opening it pours the whole stored visit back into the form.
 *
 * The entry query is read once, on mount — kept alive, the screen outlives the
 * URL it was opened with: `?record=` resumes a saved visit, `?draft=` completes
 * a nurse triage draft, `?appointment=` attends a scheduled one, none is a
 * walk-in. `?appointment=` alone still finds the nurse's draft.
 */
export function EncounterSection() {
  const { patient, patientId } = usePatientWorkspace();
  // Ties each vital label to its own control; a bare <label> announces nothing
  // and clicking it does not focus the field.
  const uid = useId();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const doctor = useAuthStore((s) => s.doctor);
  const { data: professionalProfile } = useQuery({
    queryKey: ['profile'],
    queryFn: fetchProfile,
    enabled: Boolean(doctor),
  });
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const ensureHonorific = useHonorificGate();
  const { can } = useWorkspaceCapabilities();
  const clinicalModules = useClinicalModules();
  const canUseGynecoObstetrics = clinicalModules.has('gyneco_obstetrics');
  const navigate = useNavigate();
  const location = useLocation();
  const [searchParams, setSearchParams] = useSearchParams();
  const [entry] = useState(() =>
    useEncounterGuardStore.getState().discardEntryQuery
      ? { draftId: null, appointmentId: null, recordId: null, type: null }
      : {
          draftId: Number(searchParams.get('draft')) || null,
          appointmentId: Number(searchParams.get('appointment')) || null,
          recordId: Number(searchParams.get('record')) || null,
          // `?tipo=prenatal` from the gyneco-obstetric file: the control opens
          // already on its template.
          type: searchParams.get('tipo'),
        },
  );
  const { draftId, appointmentId } = entry;
  // Consumed after mount, not in the initializer: StrictMode runs that twice.
  useEffect(() => {
    useEncounterGuardStore.getState().consumeDiscardEntryQuery();
  }, []);

  // Any doctor who can manage records gets the closure screen on «Terminar».
  const closureFlow = can('records.manage');
  // Solo consultorio vs clinic with a cash desk, without a setting of its own:
  // the owner of a personal workspace holds billing.manage, the `doctor` role in
  // a clinic does not. Presentation only — the server drops the price anyway.
  const canPriceServices = can('billing.manage');
  const visibleSteps = can('records.view') ? STEPS : STEPS.filter((entry) => entry.key !== 'certificate');
  const stepIndex = (key: StepKey) => visibleSteps.findIndex((entry) => entry.key === key);
  const [closed, setClosed] = useState(false);
  const [completedAppointmentId, setCompletedAppointmentId] = useState<number | null>(null);

  // `?tab=rx` (from «Editar receta») opens straight on that tab, so fixing a
  // forgotten medication does not look like starting a new visit.
  const [step, setStep] = useState<StepKey>(() => {
    const requested = searchParams.get('tab');
    return visibleSteps.find((entry) => entry.key === requested)?.key ?? 'evaluation';
  });
  const workTabsRef = useRef<HTMLDivElement>(null);
  useLayoutEffect(() => {
    const nav = workTabsRef.current;
    const active = nav?.querySelector<HTMLElement>('[role="tab"][aria-selected="true"]');
    if (!nav || !active || nav.clientWidth <= 0) return;
    const navLeft = nav.getBoundingClientRect().left;
    const rect = active.getBoundingClientRect();
    const left = rect.left - navLeft + nav.scrollLeft;
    const right = rect.right - navLeft + nav.scrollLeft;
    if (left < nav.scrollLeft) nav.scrollLeft = Math.max(0, left);
    else if (right > nav.scrollLeft + nav.clientWidth - 18) {
      nav.scrollLeft = right - nav.clientWidth + 18;
    }
  }, [step]);
  const [reason, setReason] = useState('');
  const [reasonMissing, setReasonMissing] = useState(false);
  const [diagnosis, setDiagnosis] = useState('');
  const [treatment, setTreatment] = useState('');
  const [recommendations, setRecommendations] = useState('');
  const [alarmSigns, setAlarmSigns] = useState('');
  const [controlledNo, setControlledNo] = useState('');
  const [followUpDate, setFollowUpDate] = useState('');
  const [followUpNotes, setFollowUpNotes] = useState('');
  const [currentIllness, setCurrentIllness] = useState('');
  const [evolution, setEvolution] = useState('');
  const [physicalExamination, setPhysicalExamination] = useState('');
  const [consultationType, setConsultationType] = useState<ConsultationType>(() =>
    entry.type && (Object.keys(CONSULTATION_TYPE_LABELS) as string[]).includes(entry.type)
      ? (entry.type as ConsultationType)
      : 'general',
  );
  const [professionalTitle, setProfessionalTitle] = useState(doctor?.specialty ?? '');
  const professionalTitles = Array.from(new Set([
    ...(professionalProfile?.specialties ?? []).map((specialty) => specialty.name),
    ...(doctor?.specialty ? [doctor.specialty] : []),
    ...(professionalTitle ? [professionalTitle] : []),
  ]));
  // A type switch must not smuggle hidden fields into a different template.
  // Keep each in-progress template separately so returning to it restores its
  // draft, while the payload contains only the selected type's values.
  const [structuredExams, setStructuredExams] = useState<Partial<Record<ConsultationType, Record<string, string>>>>({});
  const [reviewOfSystems, setReviewOfSystems] = useState('');
  const [painScale, setPainScale] = useState('');
  const [vitals, setVitals] = useState<Record<VitalKey, string>>(EMPTY_VITALS);
  const [headCircumference, setHeadCircumference] = useState('');
  const [measurementPosition, setMeasurementPosition] = useState<'recumbent_length' | 'standing_height' | ''>('');
  const [diagnoses, setDiagnoses] = useState<DiagnosisRow[]>([]);
  const [prescriptions, setPrescriptions] = useState<PrescriptionRow[]>([]);
  const [services, setServices] = useState<RecordServiceInput[]>([]);
  const [ordersPrefill, setOrdersPrefill] = useState<OrdersPrefill | null>(null);
  // Score MAMA: se despliega solo en control prenatal y queda tras un interruptor
  // en el resto — el instrumento también cubre a la puérpera, que no llega
  // etiquetada como prenatal.
  const [scoreMama, setScoreMama] = useState<ScoreMamaCapture>(EMPTY_SCORE_MAMA);
  const [baselineHints, setBaselineHints] = useState<BaselineVitals | null>(null);
  const [baselineLoaded, setBaselineLoaded] = useState(false);

  // The visit this screen owns once Guardar has run once (or `?record=` brought
  // it in). From then on every save is a PUT to it and autosave is armed.
  const [savedRecordId, setSavedRecordId] = useState<number | null>(entry.recordId);
  const [savedAt, setSavedAt] = useState<Date | null>(null);
  // A resumed visit is not editable until it has been poured into the form —
  // autosaving the empty screen over it would wipe it.
  const [hydrated, setHydrated] = useState(!entry.recordId);
  // What the server holds, as the JSON of the form content last sent. Dirty is
  // "the form differs from this"; a failed save remembers what it tried so it
  // does not retry the same content in a loop.
  const lastSavedJson = useRef<string | null>(null);
  const lastSavedContent = useRef<Partial<MedicalRecordInput> | null>(null);
  const [failedJson, setFailedJson] = useState<string | null>(null);
  const [cleanPending, setCleanPending] = useState(false);

  const availableConsultationTypes = (Object.keys(CONSULTATION_TYPE_LABELS) as ConsultationType[]).filter(
    (type) => canUseGynecoObstetrics || !GYNECO_OBSTETRICS_TYPES.includes(type),
  );

  // A profile can lose the specialty capability while this tab stays open. Do
  // not leave an ineligible type selected and let the doctor discover the
  // server-side 403 only when saving.
  useEffect(() => {
    if (!canUseGynecoObstetrics && GYNECO_OBSTETRICS_TYPES.includes(consultationType)) {
      setConsultationType('general');
    }
  }, [canUseGynecoObstetrics, consultationType]);

  // ── Resume a saved visit (?record=) ─────────────────────────────────────
  const { data: resumedRecord, isError: resumeFailed } = useQuery({
    queryKey: ['record', entry.recordId],
    queryFn: () => fetchRecord(entry.recordId!),
    enabled: !!entry.recordId,
  });

  useEffect(() => {
    if (hydrated) return;
    if (resumeFailed || (resumedRecord && resumedRecord.patient_id !== patientId)) {
      // A stale or foreign link must not write over someone else's visit.
      setSavedRecordId(null);
      setHydrated(true);
      toast('No se encontró esa atención. Se abrió una atención nueva.', 'info');
      return;
    }
    if (!resumedRecord) return;
    const f = fieldsFromRecord(resumedRecord);
    setReason(f.reason);
    setDiagnosis(f.diagnosis);
    setTreatment(f.treatment);
    setRecommendations(f.recommendations);
    setAlarmSigns(f.alarmSigns);
    setControlledNo(f.controlledNo);
    setFollowUpDate(f.followUpDate);
    setFollowUpNotes(f.followUpNotes);
    setCurrentIllness(f.currentIllness);
    setEvolution(f.evolution);
    setPhysicalExamination(f.physicalExamination);
    setConsultationType(f.consultationType);
    setProfessionalTitle(f.professionalTitle);
    setStructuredExams({ [f.consultationType]: f.structuredExam });
    setReviewOfSystems(f.reviewOfSystems);
    setPainScale(f.painScale);
    setVitals(f.vitals);
    setHeadCircumference(f.headCircumference);
    setMeasurementPosition(f.measurementPosition);
    setDiagnoses(f.diagnoses);
    setPrescriptions(f.prescriptions);
    setServices(f.services);
    setScoreMama(f.scoreMama);
    setBaselineLoaded(true);
    setHydrated(true);
    // The form now equals the server: take that as the clean baseline on the
    // next render, once the state above has landed.
    setCleanPending(true);
  }, [resumedRecord, resumeFailed, hydrated, patientId, toast]);

  // ── Nurse triage draft (?draft= / ?appointment=) ────────────────────────
  const { data: draftFromUrl } = useQuery({
    queryKey: ['record', draftId],
    queryFn: () => fetchRecord(draftId!),
    enabled: !!draftId,
  });

  // Only the queue knows the draft's id, so only the queue can put it in
  // `?draft=`. The agenda, the dashboard, the patient file and "Atender" link
  // with `?appointment=` alone — and without this the doctor was asked again
  // for vitals the nurse had already taken, on every triaged patient.
  const { data: patientRecords, isFetched: patientRecordsFetched } = useQuery({
    queryKey: ['patient-records', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientRecords(patientId),
    // Also on a plain «Iniciar atención»: to offer today's visit back (below).
    enabled: Number.isFinite(patientId) && !draftId && (!!appointmentId || !entry.recordId),
  });

  // A doctor thrown out mid-visit (or who closed the tab) and back through
  // «Iniciar atención» started over on a blank form and registered the patient
  // twice. Today's visit is offered back instead of silently duplicated.
  const todaysVisit = !appointmentId && !entry.recordId && !draftId
    ? (patientRecords?.data ?? []).find(
        (r) =>
          (r.status === 'completed' || r.status === 'in_progress')
          && !!r.created_at
          && new Date(r.created_at).toDateString() === new Date().toDateString(),
      ) ?? null
    : null;

  // An open triage record is `draft` (opened) or `vitals_done` (vitals taken);
  // both are the same visit waiting for the doctor. A `completed` one is a past
  // visit and must never be reopened as this one.
  const draftFromAppointment =
    (patientRecords?.data ?? []).find(
      (r) =>
        r.appointment_id === appointmentId &&
        (r.status === 'draft' || r.status === 'vitals_done'),
    ) ?? null;

  // Coming back to an appointment already attended (the agenda, the dashboard,
  // «Atender» again) must reopen that visit with everything it holds, not a
  // blank form — a doctor who stepped away mid-consultation lost the screen
  // and found it empty, with the prescription still unwritten.
  const attendedFromAppointment =
    (patientRecords?.data ?? []).find(
      (r) => r.appointment_id === appointmentId && (r.status === 'completed' || r.status === 'in_progress'),
    ) ?? null;
  useEffect(() => {
    if (!attendedFromAppointment || draftFromAppointment || entry.recordId) return;
    window.location.replace(`/pacientes/${patientId}/atencion?record=${attendedFromAppointment.id}`);
  }, [attendedFromAppointment, draftFromAppointment, entry.recordId, patientId]);

  const draftRecord = draftFromUrl ?? draftFromAppointment;
  const activeDraftId = draftId ?? draftFromAppointment?.id ?? null;
  // The one record every write goes to: the doctor's saved visit, else the
  // nurse's draft being completed. Never two records for one visit.
  const writeTargetId = savedRecordId ?? activeDraftId;
  const structuredExam = structuredExams[consultationType] ?? {};

  const { data: baselineVitals } = useQuery({
    queryKey: ['baseline-vitals', activeWorkspaceId, patientId],
    queryFn: () => fetchBaselineVitals(patientId),
    enabled: !!patientId && !draftId && !entry.recordId,
  });

  useEffect(() => {
    const v = draftRecord?.vitals?.[0];
    if (!v) return;
    setVitals((prev) => ({
      ...prev,
      weight_kg: v.weight_kg ?? prev.weight_kg,
      height_cm: v.height_cm ?? prev.height_cm,
      bp_sys: v.blood_pressure_sys != null ? String(v.blood_pressure_sys) : prev.bp_sys,
      bp_dia: v.blood_pressure_dia != null ? String(v.blood_pressure_dia) : prev.bp_dia,
      hr: v.heart_rate != null ? String(v.heart_rate) : prev.hr,
      temp: v.temperature ?? prev.temp,
      spo2: v.oxygen_saturation != null ? String(v.oxygen_saturation) : prev.spo2,
      rr: v.respiratory_rate != null ? String(v.respiratory_rate) : prev.rr,
    }));
    setBaselineLoaded(false);
  }, [draftRecord]);

  useEffect(() => {
    if (activeDraftId || entry.recordId || !baselineVitals || baselineLoaded) return;
    setBaselineHints(baselineVitals);
    setVitals((prev) => ({
      ...prev,
      weight_kg: baselineVitals.weight_kg != null ? String(baselineVitals.weight_kg) : prev.weight_kg,
      height_cm: baselineVitals.height_cm != null ? String(baselineVitals.height_cm) : prev.height_cm,
    }));
    setBaselineLoaded(true);
  }, [baselineVitals, activeDraftId, baselineLoaded, entry.recordId]);

  // ── Payload ─────────────────────────────────────────────────────────────
  // The clinical content as typed. Kept apart from the envelope (patient,
  // appointment, finalize flags) because it is also what "dirty" compares.
  const buildContent = (): Partial<MedicalRecordInput> => ({
    reason_for_consultation: reason,
    consultation_type: consultationType,
    ...(professionalTitle ? { professional_title: professionalTitle } : {}),
    diagnosis,
    treatment,
    current_illness: currentIllness || null,
    evolution: evolution || null,
    physical_examination: physicalExamination || null,
    structured_exam: (() => {
      const entries = Object.entries(structuredExam).filter(([, v]) => v.trim() !== '');
      return entries.length ? Object.fromEntries(entries) : null;
    })(),
    review_of_systems: reviewOfSystems || null,
    pain_scale: painScale !== '' ? Number(painScale) : null,
    recommendations: recommendations || null,
    alarm_signs: alarmSigns || null,
    controlled_prescription_no: controlledNo || null,
    follow_up_date: followUpDate || null,
    follow_up_notes: followUpNotes || null,
    vitals: {
      ...(vitals.weight_kg ? { weight_kg: vitals.weight_kg } : {}),
      ...(vitals.height_cm ? { height_cm: vitals.height_cm } : {}),
      ...(headCircumference ? { head_circumference_cm: headCircumference } : {}),
      ...(measurementPosition ? { length_height_measurement_position: measurementPosition } : {}),
      ...(vitals.bp_sys ? { blood_pressure_sys: Number(vitals.bp_sys) } : {}),
      ...(vitals.bp_dia ? { blood_pressure_dia: Number(vitals.bp_dia) } : {}),
      ...(vitals.hr ? { heart_rate: Number(vitals.hr) } : {}),
      ...(vitals.temp ? { temperature: vitals.temp } : {}),
      ...(vitals.spo2 ? { oxygen_saturation: Number(vitals.spo2) } : {}),
      ...(vitals.rr ? { respiratory_rate: Number(vitals.rr) } : {}),
      // El puntaje NO viaja: el servidor lo calcula con estos valores.
      ...(scoreMama.oxygen_therapy ? { oxygen_therapy: scoreMama.oxygen_therapy } : {}),
      ...(scoreMama.consciousness_level ? { consciousness_level: scoreMama.consciousness_level } : {}),
      ...(scoreMama.urine_output_ml_h ? { urine_output_ml_h: Number(scoreMama.urine_output_ml_h) } : {}),
      ...(scoreMama.urine_output_not_quantifiable ? { urine_output_not_quantifiable: true } : {}),
    },
    diagnoses: diagnoses.map((d) => ({
      cie10_code: d.cie10_code,
      cie10_description: d.cie10_description,
      type: d.type,
    })),
    // Only the name is required to keep a row: posology stopped being captured
    // on 2026-09-21, so filtering on it would drop every new prescription.
    prescriptions: prescriptions.map(prescriptionRowToPayload).filter((p) => p.medication_name),
    services: services.filter((s) => s.name.trim()),
  });

  const content = buildContent();
  const contentJson = JSON.stringify(content);

  useEffect(() => {
    if (!cleanPending) return;
    lastSavedJson.current = contentJson;
    lastSavedContent.current = JSON.parse(contentJson) as Partial<MedicalRecordInput>;
    setCleanPending(false);
  }, [cleanPending, contentJson]);

  // Diagnosis and plan are what a complete visit carries. Saving without them
  // is allowed — the doctor saves as they go — but the record says so.
  const coreComplete = Boolean((diagnosis.trim() || diagnoses.length) && treatment.trim());

  // Rows the server deletes and recreates on every write travel only when they
  // changed, so an autosave of a typo in the plan does not churn the receta.
  const buildPayload = (id: number | null): MedicalRecordInput => {
    const body: Partial<MedicalRecordInput> = { ...content };
    const last = lastSavedContent.current;
    if (id && last) {
      for (const key of ['diagnoses', 'prescriptions', 'services'] as const) {
        if (JSON.stringify(body[key]) === JSON.stringify(last[key])) delete body[key];
      }
    }
    return {
      patient_id: patientId,
      ...(appointmentId && !id ? { appointment_id: appointmentId } : {}),
      ...body,
      reason_for_consultation: reason,
      // Always explicit: an absent flag finalizes on the server anyway, and
      // `false` would reopen a finished visit.
      finalize: true,
      closed_with_override: !coreComplete,
    } as MedicalRecordInput;
  };

  const saveMutation = useMutation({
    mutationFn: ({ id, payload }: { id: number | null; payload: MedicalRecordInput }) =>
      id ? completeRecord(id, payload) : createRecord(payload),
  });

  // The reason is the one field the server needs to open a visit, so it is
  // also what arms the autosave: from the moment it is written, the atención
  // saves itself — there is no "first Guardar" to remember.
  //
  // Arriving by appointment, the nurse's draft is looked up first: saving
  // before the answer would open a second record for the same visit.
  const draftLookupPending = !!appointmentId && !draftId && !patientRecordsFetched;
  const dirty = hydrated && !draftLookupPending && reason.trim() !== '' && contentJson !== lastSavedJson.current;
  const saveFailed = failedJson !== null && failedJson === contentJson;

  /**
   * One save path for Guardar, the autosave, the receta and Terminar. Returns
   * the saved record, or null when it did not save (and, if manual, said why).
   */
  const save = async (mode: SaveMode): Promise<MedicalRecord | null> => {
    const manual = mode === 'manual';
    if (voidedRef.current) return null;
    if (!reason.trim()) {
      if (manual) {
        setReasonMissing(true);
        document.getElementById('enc-reason')?.focus();
        toast('Escribe el motivo de atención para guardarla.', 'error');
      }
      return null;
    }
    // Quantity ("cantidad a dispensar") is required for every prescribed drug.
    if (missingDispensingQuantity(prescriptions)) {
      if (manual) toast('Indica la cantidad a dispensar en cada medicamento.', 'error');
      return null;
    }
    if (draftLookupPending) {
      if (manual) toast('Cargando la atención de enfermería, intenta en un momento.', 'info');
      return null;
    }

    const id = writeTargetId;
    const sentJson = contentJson;
    const firstSave = savedRecordId == null;
    try {
      // Taking the nurse's draft from the shared waiting room: tell the server
      // nobody had signed it yet, so a colleague who got there first is not
      // silently overwritten (409).
      const takesDraft = firstSave && id !== null && id === activeDraftId;
      const record = await saveMutation.mutateAsync({
        id,
        payload: takesDraft ? { ...buildPayload(id), expected_created_by: null } : buildPayload(id),
      });
      lastSavedJson.current = sentJson;
      lastSavedContent.current = JSON.parse(sentJson) as Partial<MedicalRecordInput>;
      setFailedJson(null);
      setSavedAt(new Date());
      qc.invalidateQueries({ queryKey: ['patient-records', activeWorkspaceId, patientId] });
      qc.invalidateQueries({ queryKey: ['billables'] });
      if (firstSave) {
        setSavedRecordId(record.id);
        setCompletedAppointmentId(record.appointment_id ?? appointmentId ?? null);
        qc.invalidateQueries({ queryKey: ['patient', activeWorkspaceId, patientId] });
        qc.invalidateQueries({ queryKey: ['clinic-queue'] });
        qc.invalidateQueries({ queryKey: ['dashboard'] });
        qc.invalidateQueries({ queryKey: ['appointments'] });
      }
      return record;
    } catch (error) {
      setFailedJson(sentJson);
      // 409: a colleague already signed this visit (shared waiting room). Said
      // even on autosave — the doctor must know their notes are not landing.
      const takenByColleague = isAxiosError(error) && error.response?.status === 409;
      if (manual || takenByColleague) toast(serverMessage(error) ?? 'No se pudo guardar la atención.', 'error');
      return null;
    }
  };

  // Latest save/dirty for the timers and the unmount flush below, which would
  // otherwise close over the render that scheduled them.
  const saveRef = useRef(save);
  saveRef.current = save;
  const flushRef = useRef<() => void>(() => {});
  flushRef.current = () => {
    if (voidedRef.current || !dirty || saveMutation.isPending || missingDispensingQuantity(prescriptions)) return;
    const id = writeTargetId;
    const payload = buildPayload(id);
    // Fire and forget: the screen is going away and has nobody left to tell.
    void Promise.resolve()
      .then(() => (id ? completeRecord(id, payload) : createRecord(payload)))
      .catch(() => {});
  };

  // ── Autosave: a moment after the doctor stops typing ────────────────────
  useEffect(() => {
    if (!dirty || saveFailed || saveMutation.isPending) return;
    const timer = window.setTimeout(() => void saveRef.current('auto'), encounterTiming.autosaveDelayMs);
    return () => window.clearTimeout(timer);
  }, [contentJson, dirty, saveFailed, saveMutation.isPending]);

  // A reload, or a link back from elsewhere, resumes this same visit. Synced
  // whenever the atención is the visible section: the first autosave may land
  // while the doctor is looking at Historial.
  const onEncounterRoute = location.pathname.endsWith('/atencion');
  useEffect(() => {
    if (!savedRecordId || !onEncounterRoute) return;
    if (searchParams.get('record') === String(savedRecordId)) return;
    setSearchParams({ record: String(savedRecordId) }, { replace: true });
  }, [savedRecordId, onEncounterRoute, searchParams, setSearchParams]);

  // Leaving the patient unmounts this screen: send what the timer had not.
  useEffect(() => () => flushRef.current(), []);

  useEffect(() => {
    if (reason.trim()) setReasonMissing(false);
  }, [reason]);

  // Descarga la receta sin salir de la atención. Guarda antes: el PDF sale del
  // servidor, así que debe reflejar el medicamento que se acaba de escribir.
  const [downloadingRx, setDownloadingRx] = useState(false);
  const downloadPrescription = async () => {
    await ensureHonorific();
    setDownloadingRx(true);
    try {
      const record = await save('manual');
      if (!record) return;
      const blob = await downloadPrescriptionPdf(record.id);
      downloadBlob(blob, `receta-${record.id}.pdf`);
    } catch (error) {
      toast(serverMessage(error) ?? 'No se pudo descargar la receta.', 'error');
    } finally {
      setDownloadingRx(false);
    }
  };

  // Vitals stay out on purpose: they can arrive pre-filled from triage.
  const hasTypedWork = Boolean(
    reason.trim() ||
      diagnosis.trim() ||
      treatment.trim() ||
      currentIllness.trim() ||
      evolution.trim() ||
      physicalExamination.trim() ||
      recommendations.trim() ||
      alarmSigns.trim() ||
      diagnoses.length ||
      prescriptions.length ||
      services.length,
  );
  // Work the server does not have and will not get by itself: typed without a
  // reason (nothing can be saved yet), or a save that failed.
  const atRisk = (hasTypedWork && !reason.trim()) || saveFailed;

  // Closing or reloading the tab: an unsaved visit, or edits the autosave has
  // not sent yet, would be lost — the browser asks first.
  const unloadRisk = atRisk || dirty;
  useEffect(() => {
    if (!unloadRisk) return;
    const onBeforeUnload = (e: BeforeUnloadEvent) => {
      e.preventDefault();
      e.returnValue = '';
    };
    window.addEventListener('beforeunload', onBeforeUnload);
    return () => window.removeEventListener('beforeunload', onBeforeUnload);
  }, [unloadRisk]);

  // Leaving the patient (list, search, workspace) confirms only when the work
  // would really be lost; moving between sections never does — the screen
  // stays mounted.
  const setHasUnsavedWork = useEncounterGuardStore((s) => s.setHasUnsavedWork);
  const startNewEncounter = useEncounterGuardStore((s) => s.startNewEncounter);
  useEffect(() => {
    setHasUnsavedWork(atRisk && !closed);
    return () => setHasUnsavedWork(false);
  }, [atRisk, closed, setHasUnsavedWork]);

  const terminate = async () => {
    const record = await save('manual');
    if (!record) return;
    setCompletedAppointmentId(record.appointment_id ?? appointmentId ?? null);
    if (closureFlow) setClosed(true);
    else navigate(`/pacientes/${patientId}/historial`);
  };

  // Voiding: nothing may write to the record afterwards, then the doctor
  // lands on the history, where the visit is gone.
  const voidedRef = useRef(false);
  const [voidOpen, setVoidOpen] = useState(false);
  const onVoided = () => {
    voidedRef.current = true;
    setVoidOpen(false);
    qc.invalidateQueries({ queryKey: ['patient-records', activeWorkspaceId, patientId] });
    qc.invalidateQueries({ queryKey: ['patient', activeWorkspaceId, patientId] });
    qc.invalidateQueries({ queryKey: ['appointments'] });
    qc.invalidateQueries({ queryKey: ['clinic-queue'] });
    qc.invalidateQueries({ queryKey: ['dashboard'] });
    qc.invalidateQueries({ queryKey: ['billables'] });
    toast('Atención anulada.', 'success');
    startNewEncounter();
    navigate(`/pacientes/${patientId}/historial`, { replace: true });
  };

  const newEncounter = () => {
    if (atRisk && !window.confirm('Esta atención tiene cambios sin guardar. ¿Empezar una nueva y descartarlos?')) return;
    startNewEncounter();
    navigate(`/pacientes/${patientId}/atencion`, { replace: true });
  };

  // "Atender al siguiente": jump to the next ready patient (same doctor);
  // fall back to the queue when none is left.
  const attendNext = async () => {
    try {
      const next = await fetchNextPatient(doctor?.id);
      if (next?.draft_record_id) {
        navigate(`/pacientes/${next.patient_id}/atencion?draft=${next.draft_record_id}`);
      } else {
        toast('No hay más pacientes listos en la cola.', 'info');
        navigate('/cola');
      }
    } catch {
      navigate('/cola');
    }
  };

  // «Escuchar la consulta» is off until the doctor turns it on in Ajustes → Agentes.
  // A hook: it must stay above the early returns below, or closing the visit
  // renders fewer hooks and the screen crashes.
  const eloAgents = useQuery({ queryKey: ['elo', 'agents'], queryFn: fetchEloAgents, enabled: can('assistant.use') && can('records.manage') });
  const listening = eloAgents.data?.some((a) => a.key === 'dictation' && a.enabled) ?? false;

  if (closed) {
    return (
      <EncounterClosureScreen
        patient={patient}
        diagnosis={diagnosis}
        completedAppointmentId={completedAppointmentId}
        services={services.map((s) => ({
          medical_service_id: s.medical_service_id ?? null,
          name: s.name,
          category: s.category ?? null,
          quantity: String(s.quantity ?? 1),
          unit_price: s.unit_price ?? null,
        }))}
        followUpDate={followUpDate || null}
        onAttendNext={attendNext}
      />
    );
  }

  // The ✓ marks what a complete visit carries; saving never waits for it.
  const sectionFilled: Partial<Record<StepKey, boolean>> = {
    dx: Boolean(diagnosis.trim() || diagnoses.length),
    rx: Boolean(treatment.trim()),
  };
  const saving = saveMutation.isPending;
  const statusTone = saving ? 'saving' : saveFailed ? 'error' : !savedRecordId && !dirty ? 'new' : dirty ? 'pending' : 'saved';
  const statusLabel =
    statusTone === 'saving' ? 'Guardando…'
      : statusTone === 'error' ? 'No se pudo guardar'
        : statusTone === 'new' ? 'Se guarda sola al escribir el motivo'
          : statusTone === 'pending' ? 'Guardando en un momento…'
            : savedAt ? `Guardado ${clockTime(savedAt)}` : 'Guardada';
  const allergies = patient.allergies ?? [];

  // Elo's view of the atención: the clinical picture only (ADR-017). No id,
  // name or cédula — the server would not forward them, and they are not sent.
  const eloDiagnoses = [
    ...diagnoses.map((d) => ({ code: d.cie10_code || null, description: d.cie10_description || null })),
    ...(diagnosis.trim() ? [{ code: null, description: diagnosis.trim().slice(0, 255) }] : []),
  ];
  const eloWeight = Number(String(vitals.weight_kg ?? '').replace(',', '.'));

  const eloContext: EloContext = {
    age: patient.age ?? null,
    sex: patient.gender === 'male' || patient.gender === 'female' || patient.gender === 'other' ? patient.gender : null,
    weight_kg: Number.isFinite(eloWeight) && eloWeight > 0 ? eloWeight : null,
    reason: reason.trim().slice(0, 1000) || null,
    illness: currentIllness.trim().slice(0, 3000) || null,
    exam: physicalExamination.trim().slice(0, 3000) || null,
    vitals_summary:
      VITAL_FIELDS.filter(([key]) => vitals[key]?.trim())
        .map(([key, label]) => `${label}: ${vitals[key].trim()}`)
        .join(', ')
        .slice(0, 300) || null,
    diagnoses: eloDiagnoses.slice(0, 10),
    allergies: allergies.map((a) => a.allergen_name ?? a.allergen_type ?? '').filter(Boolean).slice(0, 20),
  };

  /** Elo's receta, once the doctor accepts it: same state the Prescripción tab edits, so autosave keeps it. */
  const applyEloRx = (rows: PrescriptionRow[], mode: 'replace' | 'append', edit: boolean) => {
    setPrescriptions((prev) => (mode === 'append' ? [...prev.filter((p) => p.medication_name.trim()), ...rows] : rows));
    if (edit) setStep('rx');
  };

  /** Elo's tidy note: the enfermedad actual, plus CIE-10 codes not already on the atención. */
  const applyEloNote = (note: EloNoteSuggestion, mode: 'replace' | 'append', edit: boolean) => {
    if (note.illness) setCurrentIllness((prev) => mergeText(prev, note.illness, mode));
    addEloDiagnoses(note.diagnoses);
    if (edit) setStep('dx');
  };

  /** "Escuchar con Elo": every field it heard, plus its CIE-10. Empty proposals leave a field alone. */
  const applyDictation = (heard: EloDictationSuggestion, mode: 'replace' | 'append', edit: boolean) => {
    if (heard.reason) {
      setReason((prev) => mergeText(prev, heard.reason, mode));
      setReasonMissing(false);
    }
    if (heard.illness) setCurrentIllness((prev) => mergeText(prev, heard.illness, mode));
    if (heard.evolution) setEvolution((prev) => mergeText(prev, heard.evolution, mode));
    if (heard.exam) setPhysicalExamination((prev) => mergeText(prev, heard.exam, mode));
    addEloDiagnoses(heard.diagnoses);
    if (edit) setStep(heard.exam ? 'evaluation' : 'dx');
  };

  /** CIE-10 codes not already on the atención. */
  const addEloDiagnoses = (proposed: EloNoteSuggestion['diagnoses']) => {
    setDiagnoses((prev) => {
      const known = new Set(prev.map((d) => d.cie10_code));
      const added = proposed
        .filter((d) => !known.has(d.code))
        .map((d, i) => ({
          cie10_code: d.code,
          cie10_description: d.description,
          // Only one primary: the first proposal takes it if the atención has none yet.
          type: d.type === 'primary' && (prev.some((p) => p.type === 'primary') || i > 0) ? ('secondary' as const) : d.type,
        }));
      return [...prev, ...added];
    });
  };

  /** Elo's exams go to the Órdenes tab, filled in; the doctor still generates the order. */
  const applyEloExams = (exams: EloExamsSuggestion) => {
    setOrdersPrefill({
      key: Date.now(),
      type: exams.type,
      clinicalInfo: exams.clinical_info,
      items: exams.items.map((i) => ({ study_id: i.study_id, name: i.name, category: i.category })),
    });
    setStep('orders');
  };
  const bmi = computeBmi(vitals.weight_kg, vitals.height_cm);
  // Los cortes de la OMS son de adulto: en pediatría el IMC se lee contra
  // percentiles por edad y sexo, así que ahí no se clasifica.
  const bmiClass = consultationType === 'pediatric' ? null : bmiClassification(bmi);

  if (!hydrated) {
    return <div className="enc-body enc-loading muted">Abriendo la atención…</div>;
  }

  return (
    <div className="enc-body">
      <div className="enc-status">
        <span className="enc-status-title">
          <Icon name="steth" size={15} />
          {savedRecordId ? `Atención #${savedRecordId}` : 'Nueva atención'}
        </span>
        <span className={`enc-status-info ${statusTone}`} role="status">
          {statusLabel}
        </span>
        {saveFailed && (
          <button className="btn ghost sm" type="button" onClick={() => void save('manual')}>
            Reintentar
          </button>
        )}
      </div>

      {todaysVisit && !savedRecordId && (
        <div className="alert amber alert-spaced" role="status">
          <span className="ai"><Icon name="alert" size={15} /></span>
          <div>
            <b>Este paciente ya tiene una atención de hoy</b>
            <p>
              {todaysVisit.reason_for_consultation ?? 'Consulta'}. Si es la misma consulta, síguela para no
              registrarla dos veces.
            </p>
            <a
              className="btn sm primary alert-cta"
              href={`/pacientes/${patientId}/atencion?record=${todaysVisit.id}`}
            >
              Seguir la atención de hoy
            </a>
          </div>
        </div>
      )}

      <div className="enc-grid">
        <section className="enc-col patient" aria-label="Datos de la atención">
          <div className="pr-alerts enc-patient-alerts" aria-label="Avisos del paciente">
            {(patient.alerts ?? []).map((alert) => <PatientAlertChip key={alert.id} alert={alert} />)}
            {canUseGynecoObstetrics && patient.active_pregnancy && (
              <PregnancyChip pregnancy={patient.active_pregnancy} />
            )}
            {/* "Sin alergias" already reads in the patient header; only active
                allergies repeat here, next to the prescription. */}
          </div>
          {allergies.length > 0 && (
            <div className="alert red enc-allergy-alert">
              <span className="ai"><Icon name="alert" size={15} /></span>
              <div>
                <b>Alergias activas</b>
                <p>
                  {allergies.map((a) => a.allergen_name ?? a.allergen_type).filter(Boolean).join(' · ')} — verificar
                  antes de prescribir.
                </p>
              </div>
            </div>
          )}
          <Card className="enc-section enc-patient-card">
            <h4>Datos de la atención</h4>
            <div className="enc-field">
              <label className="field-label" htmlFor={`${uid}-consultation-type`}>Tipo de consulta</label>
              <select
                id={`${uid}-consultation-type`}
                className="field"
                value={consultationType}
                onChange={(e) => setConsultationType(e.target.value as ConsultationType)}
              >
                {availableConsultationTypes.map((key) => (
                  <option key={key} value={key}>{CONSULTATION_TYPE_LABELS[key]}</option>
                ))}
              </select>
            </div>
            <div className="enc-field">
              <label className="field-label" htmlFor={`${uid}-professional-title`}>Firma como</label>
              {/* Free text: how the doctor signs ("Medicina General", "Médico
                  Familiar"…). The profile's specialties are suggestions. */}
              <input
                id={`${uid}-professional-title`}
                className="field"
                list={`${uid}-professional-titles`}
                maxLength={120}
                placeholder="Especialidad principal del perfil"
                value={professionalTitle}
                onChange={(event) => setProfessionalTitle(event.target.value)}
              />
              <datalist id={`${uid}-professional-titles`}>
                {professionalTitles.map((title) => <option key={title} value={title} />)}
              </datalist>
              <p className="enc-hint">Escríbelo como quieras que salga debajo de tu nombre en la receta, los certificados y las órdenes de esta atención.</p>
            </div>
            <h5 className="enc-subhead">Signos vitales</h5>
            {baselineHints?.recorded_at && (
              <p className="enc-hint">
                {baselineHints.source === 'standalone' ? 'Última toma de enfermería' : 'Última toma de signos'}: {formatDate(baselineHints.recorded_at)}.
              </p>
            )}
            {baselineHints?.source === 'standalone' && (
              <button
                className="btn sm"
                type="button"
                onClick={() => setVitals((current) => ({
                  ...current,
                  weight_kg: current.weight_kg || baselineHints.weight_kg || '',
                  height_cm: current.height_cm || baselineHints.height_cm || '',
                  bp_sys: current.bp_sys || (baselineHints.blood_pressure_sys != null ? String(baselineHints.blood_pressure_sys) : ''),
                  bp_dia: current.bp_dia || (baselineHints.blood_pressure_dia != null ? String(baselineHints.blood_pressure_dia) : ''),
                  hr: current.hr || (baselineHints.heart_rate != null ? String(baselineHints.heart_rate) : ''),
                  temp: current.temp || baselineHints.temperature || '',
                  spo2: current.spo2 || (baselineHints.oxygen_saturation != null ? String(baselineHints.oxygen_saturation) : ''),
                  rr: current.rr || (baselineHints.respiratory_rate != null ? String(baselineHints.respiratory_rate) : ''),
                }))}
              >
                Usar estos signos en esta atención
              </button>
            )}
            <div className="enc-vitals-grid">
              {VITAL_FIELDS.map(([key, label]) => {
                let hint: string | null = null;
                if (baselineHints && ACUTE_VITAL_KEYS.includes(key)) {
                  if (key === 'bp_sys') hint = formatVitalHint(baselineHints.blood_pressure_sys);
                  if (key === 'bp_dia') hint = formatVitalHint(baselineHints.blood_pressure_dia);
                  if (key === 'hr') hint = formatVitalHint(baselineHints.heart_rate);
                  if (key === 'temp') hint = formatVitalHint(baselineHints.temperature, ' °C');
                  if (key === 'spo2') hint = formatVitalHint(baselineHints.oxygen_saturation, '%');
                  if (key === 'rr') hint = formatVitalHint(baselineHints.respiratory_rate);
                }
                return (
                  <div key={key} className="enc-vital">
                    <label className="field-label" htmlFor={`${uid}-${key}`}>{label}</label>
                    <input
                      id={`${uid}-${key}`}
                      className="field mono"
                      inputMode="decimal"
                      placeholder={hint ? `Últ. ${hint}` : undefined}
                      value={vitals[key]}
                      onChange={(e) => setVitals((v) => ({ ...v, [key]: e.target.value }))}
                    />
                  </div>
                );
              })}
            </div>
            {bmi && (
              <p className="enc-bmi">
                IMC <strong className="mono">{bmi}</strong> kg/m²
                {bmiClass && <> · {bmiClass}</>}
              </p>
            )}
            {consultationType === 'pediatric' && (
              <PediatricGrowthFields
                headCircumference={headCircumference}
                position={measurementPosition}
                onHeadCircumferenceChange={setHeadCircumference}
                onPositionChange={setMeasurementPosition}
              />
            )}
            {canUseGynecoObstetrics && (
              <ScoreMamaFields
                value={scoreMama}
                onChange={setScoreMama}
                measured={vitals}
                alwaysOn={consultationType === 'prenatal'}
              />
            )}

            <PatientHistoryPanel patient={patient} patientId={patientId} />
          </Card>
          <PreviousVisit patientId={patientId} excludeRecordId={writeTargetId} />
        </section>

        <section className="enc-col work" aria-label="Mesa de trabajo">
          {listening && (
            <EloDictation
              context={eloContext}
              current={{ reason, illness: currentIllness, evolution, exam: physicalExamination }}
              onApply={applyDictation}
            />
          )}
          <div className="enc-lead">
            <div className="enc-field">
              <label className="field-label" htmlFor="enc-reason">Motivo de atención *</label>
              <textarea
                id="enc-reason"
                className="field"
                placeholder="Ej.: dolor abdominal de 3 días, control de hipertensión…"
                rows={6}
                aria-invalid={reasonMissing || undefined}
                aria-describedby={reasonMissing ? 'enc-reason-error' : undefined}
                value={reason}
                onChange={(e) => setReason(e.target.value)}
              />
              {reasonMissing && (
                <p id="enc-reason-error" className="enc-field-error">Escribe el motivo para guardar la atención.</p>
              )}
            </div>
                        <div className="enc-field">
              <label className="field-label" htmlFor="enc-illness">Enfermedad actual</label>
              <textarea
                id="enc-illness"
                className="field"
                placeholder="Cuadro actual, síntomas asociados, tiempo de evolución…"
                rows={6}
                value={currentIllness}
                onChange={(e) => setCurrentIllness(e.target.value)}
              />
            </div>
            <div className="enc-field">
              <label className="field-label" htmlFor="enc-evolution">Evolución</label>
              <textarea
                id="enc-evolution"
                className="field"
                placeholder="Cómo ha estado desde la última visita, respuesta al tratamiento…"
                rows={6}
                value={evolution}
                onChange={(e) => setEvolution(e.target.value)}
              />
            </div>
          </div>

          <div className="enc-work-bar" role="tablist" aria-label="Secciones de la atención" onKeyDown={(event) => {
            if (event.key === 'Tab' && !event.shiftKey && event.target === event.currentTarget.querySelector('[aria-selected="true"]')) {
              event.preventDefault();
              document.getElementById('enc-work-panel')?.focus();
              return;
            }
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const currentIndex = stepIndex(step);
            const nextIndex = event.key === 'Home' ? 0
              : event.key === 'End' ? visibleSteps.length - 1
                : (currentIndex + (event.key === 'ArrowRight' ? 1 : -1) + visibleSteps.length) % visibleSteps.length;
            setStep(visibleSteps[nextIndex].key);
            const tabs = event.currentTarget.querySelectorAll<HTMLButtonElement>('[role="tab"]');
            tabs[nextIndex]?.focus();
            tabs[nextIndex]?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
          }}>
            <div ref={workTabsRef} className="enc-work-scroll">
              {visibleSteps.map((s) => (
                <button
                  key={s.key}
                  id={`enc-tab-${s.key}`}
                  role="tab"
                  aria-controls="enc-work-panel"
                  aria-selected={s.key === step}
                  tabIndex={s.key === step ? 0 : -1}
                  className="enc-work-tab"
                  onClick={() => setStep(s.key)}
                  type="button"
                >
                  <Icon name={s.icon} size={14} />
                  <span>{s.label}</span>
                  {sectionFilled[s.key] && (
                    <>
                      <Icon name="check" size={12} className="enc-tab-check" />
                      <span className="sr-only"> (completo)</span>
                    </>
                  )}
                </button>
              ))}
            </div>
          </div>

          <div id="enc-work-panel" className="enc-work-panel" role="tabpanel" aria-labelledby={`enc-tab-${step}`} tabIndex={0} onKeyDown={(event) => {
            if (event.key === 'Tab' && event.shiftKey && event.target === event.currentTarget) {
              event.preventDefault();
              document.getElementById(`enc-tab-${step}`)?.focus();
            }
          }}>
            {step === 'evaluation' && (
              <div className="enc-eval">
                <div className="enc-eval-exam">
                  <h4>
                    {consultationType === 'general'
                      ? 'Exploración física por segmentos'
                      : `Exploración — ${CONSULTATION_TYPE_LABELS[consultationType]}`}
                  </h4>
                  <StructuredExamFields
                    type={consultationType}
                    value={structuredExam}
                    onChange={(value) => setStructuredExams((previous) => ({ ...previous, [consultationType]: value }))}
                  />
                </div>
                <div className="enc-eval-notes">
                  {/* Lo que no cae en ningún segmento — y lo escrito en
                      consultas anteriores a la exploración por segmentos. */}
                  <div className="enc-field">
                    <label className="field-label" htmlFor={`${uid}-exam-notes`}>Notas del examen físico</label>
                    <textarea
                      id={`${uid}-exam-notes`}
                      className="field"
                      placeholder="Impresión general y hallazgos fuera de segmento…"
                      rows={3}
                      value={physicalExamination}
                      onChange={(e) => setPhysicalExamination(e.target.value)}
                    />
                  </div>
                  <div className="enc-field">
                    <label className="field-label" htmlFor={`${uid}-ros`}>Revisión por sistemas</label>
                    <textarea
                      id={`${uid}-ros`}
                      className="field"
                      placeholder="Síntomas por aparatos y sistemas…"
                      rows={3}
                      value={reviewOfSystems}
                      onChange={(e) => setReviewOfSystems(e.target.value)}
                    />
                  </div>
                  <div className="enc-field enc-field-narrow">
                    <label className="field-label" htmlFor={`${uid}-pain`}>Escala de dolor (0–10)</label>
                    <select id={`${uid}-pain`} className="field" value={painScale} onChange={(e) => setPainScale(e.target.value)}>
                      <option value="">—</option>
                      {Array.from({ length: 11 }, (_, i) => (
                        <option key={i} value={String(i)}>
                          {i}
                        </option>
                      ))}
                    </select>
                  </div>
                </div>
              </div>
            )}

            {step === 'dx' && (
              <Cie10DiagnosisPicker
                diagnoses={diagnoses}
                diagnosis={diagnosis}
                onDiagnosesChange={setDiagnoses}
                onDiagnosisChange={setDiagnosis}
                onSuggest={
                  can('assistant.use') && (eloContext.reason || eloContext.illness || eloContext.exam)
                    ? async () => (await suggestNote(eloContext)).diagnoses
                    : undefined
                }
              />
            )}

            {step === 'rx' && (
              <div className="enc-rx">
                <div className="enc-rx-head">
                  <button
                    className="btn sm"
                    type="button"
                    disabled={prescriptions.length === 0 || downloadingRx || saving}
                    title={prescriptions.length === 0 ? 'Agrega al menos un medicamento para imprimir la receta.' : undefined}
                    onClick={() => void downloadPrescription()}
                  >
                    <Icon name="download" size={14} /> {downloadingRx ? 'Generando…' : 'Imprimir receta'}
                  </button>
                </div>
                <MedicationPicker
                  prescriptions={prescriptions}
                  onChange={setPrescriptions}
                  patientId={patientId}
                  allergyNames={(patient.allergies ?? [])
                    .map((a) => a.allergen_name ?? a.allergen_type ?? '')
                    .filter(Boolean)}
                />

                {prescriptions.some((p) => p.is_controlled) && (
                  <div className="enc-field">
                    <label className="field-label" htmlFor={`${uid}-controlled`}>N° de receta especial (controlados)</label>
                    <input
                      id={`${uid}-controlled`}
                      className="field"
                      placeholder="Número del recetario oficial MSP/ACESS para psicotrópicos/estupefacientes"
                      value={controlledNo}
                      onChange={(e) => setControlledNo(e.target.value)}
                    />
                  </div>
                )}

                <div className="enc-rx-plan">
                  <div className="enc-field">
                    <label className="field-label" htmlFor={`${uid}-plan`}>Plan terapéutico</label>
                    <textarea
                      id={`${uid}-plan`}
                      className="field"
                      placeholder="Indicaciones generales, reposo, controles…"
                      rows={3}
                      value={treatment}
                      onChange={(e) => setTreatment(e.target.value)}
                    />
                  </div>
                  <div className="enc-field">
                    <label className="field-label" htmlFor={`${uid}-recs`}>Recomendaciones</label>
                    <textarea
                      id={`${uid}-recs`}
                      className="field"
                      placeholder="Dieta, hidratación, cuidados en casa… (se imprime en la receta)"
                      rows={2}
                      value={recommendations}
                      onChange={(e) => setRecommendations(e.target.value)}
                    />
                  </div>
                  <div className="enc-field">
                    <label className="field-label" htmlFor={`${uid}-alarm`}>Signos de alarma</label>
                    <textarea
                      id={`${uid}-alarm`}
                      className="field"
                      placeholder="Cuándo volver de urgencia… (se imprime en la receta)"
                      rows={2}
                      value={alarmSigns}
                      onChange={(e) => setAlarmSigns(e.target.value)}
                    />
                  </div>
                </div>

                {/* Próximo control: alimenta la lista de seguimiento, para que
                    un control indicado no dependa de que el paciente se acuerde. */}
                <div className="enc-field">
                  <label className="field-label" htmlFor={`${uid}-follow-up`}>Próximo control</label>
                  <div className="row wrap enc-follow-up">
                    <input
                      id={`${uid}-follow-up`}
                      type="date"
                      className="field enc-date"
                      value={followUpDate}
                      min={ecuadorDateInputValue()}
                      onChange={(e) => setFollowUpDate(e.target.value)}
                    />
                    <div className="pill-row">
                      {FOLLOW_UP_PRESETS.map((p) => (
                        <button
                          key={p.days}
                          type="button"
                          className={`pill ${followUpDate === addDays(p.days) ? 'active' : ''}`}
                          onClick={() => setFollowUpDate(followUpDate === addDays(p.days) ? '' : addDays(p.days))}
                        >
                          {p.label}
                        </button>
                      ))}
                    </div>
                    {followUpDate && (
                      <button type="button" className="btn ghost sm" onClick={() => { setFollowUpDate(''); setFollowUpNotes(''); }}>
                        Quitar
                      </button>
                    )}
                  </div>
                  {followUpDate && (
                    <input
                      className="field enc-follow-note"
                      aria-label="Motivo del control"
                      placeholder="Motivo del control (ej. reevaluar presión, revisar exámenes)"
                      value={followUpNotes}
                      onChange={(e) => setFollowUpNotes(e.target.value)}
                    />
                  )}
                </div>
              </div>
            )}

            {step === 'orders' && (
              <EncounterOrdersSection
                patientId={patientId}
                recordId={writeTargetId}
                defaultClinicalInfo={diagnosis || reason}
                prefill={ordersPrefill}
              />
            )}

            {step === 'services' && (
              <>
                <p className="enc-hint">
                  {canPriceServices
                    ? 'Elige del catálogo de aranceles lo que hiciste. La factura se abre con esto ya cargado.'
                    : 'Declara lo que hiciste. Caja lo recibe con la atención y le pone precio.'}
                </p>
                <PerformedServices
                  services={services}
                  onChange={setServices}
                  showPrices={canPriceServices}
                />
              </>
            )}
            {step === 'certificate' && can('records.view') && (
              <PatientCertificatesTab embedded />
            )}
          </div>
        </section>

        <div className="enc-side">
        <EncounterSummary
          diagnoses={diagnoses}
          diagnosis={diagnosis}
          prescriptions={prescriptions}
          treatment={treatment}
          followUpDate={followUpDate}
          services={services}
          printingRx={downloadingRx}
          busy={saving}
          onPrintRx={() => void downloadPrescription()}
          onTerminate={() => void terminate()}
          onNew={newEncounter}
          onVoid={savedRecordId ? () => setVoidOpen(true) : undefined}
        />
        {can('assistant.use') && (
          <EloCard
            context={eloContext}
            currentIllness={currentIllness}
            hasPrescription={prescriptions.some((p) => p.medication_name.trim())}
            onApplyRx={applyEloRx}
            onApplyNote={applyEloNote}
            onApplyExams={applyEloExams}
          />
        )}
        </div>
        {voidOpen && savedRecordId && (
          <VoidEncounterDialog recordId={savedRecordId} onClose={() => setVoidOpen(false)} onVoided={onVoided} />
        )}
      </div>
    </div>
  );
}
