// @vitest-environment jsdom
import { act, cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { fetchBaselineVitals, fetchPatientRecords } from '@/api/patients';
import { completeRecord, createRecord, downloadPrescriptionPdf, fetchRecord, voidRecord } from '@/api/records';
import { fetchProfile } from '@/api/profile';
import { useAuthStore } from '@/store/auth';
import { useEncounterGuardStore } from '@/store/encounterGuard';
import { useUiStore } from '@/store/ui';
import type { ClinicalModule, MedicalRecord, Paginated, Patient, RecordStatus, Workspace } from '@/api/types';
import type { PatientWorkspaceContext } from '@/features/patients/workspace/context';
import { EncounterSection, encounterTiming } from './EncounterSection';

vi.mock('@/api/patients', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/patients')>()),
  fetchBaselineVitals: vi.fn(),
  fetchPatientRecords: vi.fn(),
}));
vi.mock('@/api/records', () => ({
  createRecord: vi.fn(),
  completeRecord: vi.fn(),
  fetchRecord: vi.fn(),
  downloadPrescriptionPdf: vi.fn(),
  voidRecord: vi.fn(),
}));
vi.mock('@/api/clinic', () => ({ fetchNextPatient: vi.fn() }));
vi.mock('@/api/profile', () => ({ fetchProfile: vi.fn() }));
vi.mock('@/api/elo', () => ({ suggestPrescription: vi.fn(), suggestNote: vi.fn(), suggestExams: vi.fn(), suggestFromDictation: vi.fn(), fetchEloAgents: vi.fn().mockResolvedValue([]), logEloEvent: vi.fn() }));
vi.mock('@/api/studyOrders', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/studyOrders')>()),
  searchStudies: vi.fn().mockResolvedValue([]),
  createPatientOrder: vi.fn(),
}));

const patient: Patient = {
  id: 12,
  name: 'María López',
  id_number: '1712345678',
  birth_date: '1988-03-15',
  age: 38,
  gender: 'female',
  phone: '0991234567',
  email: 'maria@example.com',
  city: null,
  address: 'Av. Amazonas N34-120',
  allergies: [],
  family_background: [],
};

let workspaceContext: PatientWorkspaceContext = { patient, patientId: 12 };
vi.mock('@/features/patients/workspace/context', () => ({
  usePatientWorkspace: () => workspaceContext,
}));

/** A record as `GET /patients/{id}/records` returns it, with only what a test needs. */
function makeRecord(over: Partial<MedicalRecord> & { id: number; status: RecordStatus }): MedicalRecord {
  return {
    appointment_id: null,
    patient_id: 12,
    created_at: '2026-08-16T14:00:00Z',
    reason_for_consultation: null,
    current_illness: null,
    physical_examination: null,
    review_of_systems: null,
    pain_scale: null,
    diagnosis: null,
    treatment: null,
    follow_up_date: null,
    follow_up_notes: null,
    ...over,
  };
}

function page(records: MedicalRecord[]): Paginated<MedicalRecord> {
  return {
    data: records,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: null, last_page: 1, per_page: 20, to: null, total: records.length },
  };
}

function actAs(
  permissions: string[],
  type: 'personal' | 'clinic' = 'personal',
  clinicalModules: ClinicalModule[] = [],
) {
  useAuthStore.setState({
    activeWorkspaceId: 1,
    doctor: {
      id: 1,
      name: 'Dra. Prueba',
      id_number: null,
      email: null,
      phone: null,
      specialty: 'Medicina General',
      clinical_modules: clinicalModules,
    },
    workspaces: [
      {
        id: 1, name: type === 'clinic' ? 'Clínica' : 'Consultorio',
        type, is_clinic: type === 'clinic', permissions, roles: [],
      } as unknown as Workspace,
    ],
  });
}

/** Mounted directly, without PatientWorkspaceLayout or PatientRail around it — the
 *  patient comes from `workspaceContext` above (mocked), matching how the real layout
 *  hands it down via Outlet context. `/pacientes/:patientId/atencion` still needs a
 *  route so `useSearchParams`/`?draft=`/`?appointment=` resolve the same way they do
 *  in production. */
function renderEncounter(entry = '/pacientes/12/atencion') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[entry]}>
        <Routes>
          <Route path="/pacientes/:patientId/atencion" element={<EncounterSection />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  // The real 2.5 s would make every save test wait for it.
  encounterTiming.autosaveDelayMs = 30;
  workspaceContext = { patient, patientId: 12 };
  // jsdom does not implement createObjectURL; `downloadBlob` calls it to save the PDF.
  if (!URL.createObjectURL) {
    Object.defineProperty(URL, 'createObjectURL', {
      value: vi.fn(() => 'blob:mock'),
      writable: true,
      configurable: true,
    });
    Object.defineProperty(URL, 'revokeObjectURL', {
      value: vi.fn(),
      writable: true,
      configurable: true,
    });
  }
  vi.mocked(fetchBaselineVitals).mockResolvedValue(null as never);
  vi.mocked(fetchPatientRecords).mockResolvedValue(page([]));
  vi.mocked(fetchProfile).mockResolvedValue({
    // Already chosen: «¿Cómo firmas?» is covered in HonorificPrompt.test.tsx.
    honorific: 'Dr.',
    specialties: [
      { id: 1, name: 'Medicina General', is_primary: true },
      { id: 2, name: 'Ginecología y Obstetricia', is_primary: false },
    ],
  } as never);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, workspaces: [] });
  useEncounterGuardStore.setState({ hasUnsavedWork: false, discardEntryQuery: false });
  useUiStore.setState({ toasts: [] });
  vi.restoreAllMocks();
});

describe('EncounterSection', () => {
  it('saves the title chosen for this consultation separately from its type', async () => {
    const user = userEvent.setup();
    actAs(['records.view', 'records.manage', 'vitals.create']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: null } as never);
    renderEncounter();

    const title = await screen.findByLabelText('Firma como') as HTMLInputElement;
    await user.clear(title);
    await user.type(title, 'Ginecología y Obstetricia');
    await user.selectOptions(screen.getByLabelText('Tipo de consulta'), 'general');
    await user.type(screen.getByLabelText(/Motivo de atención/), 'Control general');

    await waitFor(() => expect(createRecord).toHaveBeenCalled());
    expect(vi.mocked(createRecord).mock.calls[0][0]).toMatchObject({
      consultation_type: 'general',
      professional_title: 'Ginecología y Obstetricia',
    });
  });

  it('renders patient context beside the clinical evaluation', async () => {
    renderEncounter();

    // El contexto trae el formulario de signos vitales y antecedentes; no carga
    // su propio paciente: los dos dependen de `workspaceContext`.
    expect(await screen.findByLabelText('Peso (kg)')).toBeTruthy();
    expect(screen.getByRole('button', { name: /Antecedentes del paciente/i })).toBeTruthy();
  });

  it('lets the doctor use nursing signs saved without an appointment', async () => {
    const user = userEvent.setup();
    vi.mocked(fetchBaselineVitals).mockResolvedValue({
      source: 'standalone', recorded_at: '2026-10-13T13:00:00Z',
      weight_kg: '70.50', height_cm: '172.00', head_circumference_cm: null,
      blood_pressure_sys: 120, blood_pressure_dia: 80, heart_rate: 72,
      temperature: '36.5', oxygen_saturation: '98.0', respiratory_rate: 18,
    });
    renderEncounter();

    const heartRate = await screen.findByLabelText('FC (lpm)') as HTMLInputElement;
    expect(heartRate.value).toBe('');
    await user.click(await screen.findByRole('button', { name: 'Usar estos signos en esta atención' }));
    expect(heartRate.value).toBe('72');
    expect((screen.getByLabelText('Peso (kg)') as HTMLInputElement).value).toBe('70.50');
  });

  it('opens the antecedentes panel', async () => {
    const user = userEvent.setup();
    renderEncounter();

    await user.click(await screen.findByRole('button', { name: /Antecedentes del paciente/i }));

    expect(screen.getByText('Antecedentes personales')).toBeTruthy();
  });

  it('classifies the BMI it already computes', async () => {
    const user = userEvent.setup();
    renderEncounter();

    await user.type(await screen.findByLabelText('Peso (kg)'), '70');
    await user.type(screen.getByLabelText('Talla (cm)'), '175');

    expect(screen.getByText(/22\.9/)).toBeTruthy();
    expect(screen.getByText(/Normal/)).toBeTruthy();
  });

  it('keeps in-progress exploration separate when the consultation type changes', async () => {
    const user = userEvent.setup();
    actAs([], 'personal', ['gyneco_obstetrics']);
    renderEncounter();

    await user.selectOptions(await screen.findByLabelText('Tipo de consulta'), 'gynecological');
    await user.click(screen.getByRole('tab', { name: /Exploración/ }));
    await user.type(screen.getByLabelText('Gestas'), '2');

    await user.selectOptions(screen.getByLabelText('Tipo de consulta'), 'pediatric');
    expect(screen.queryByLabelText('Gestas')).toBeNull();
    expect((screen.getByLabelText('Motor grueso') as HTMLInputElement).value).toBe('');

    await user.selectOptions(screen.getByLabelText('Tipo de consulta'), 'gynecological');
    expect((screen.getByLabelText('Gestas') as HTMLInputElement).value).toBe('2');
  });

  // The nurse's vitals reached the doctor only when the doctor came from the
  // queue, because only the queue knows the draft's id and puts it in ?draft=.
  it('loads the nurse triage vitals when it only knows the appointment', async () => {
    vi.mocked(fetchPatientRecords).mockResolvedValue(
      page([
        makeRecord({
          id: 91,
          status: 'vitals_done',
          appointment_id: 1237,
          vitals: [
            {
              id: 5,
              weight_kg: '68.40',
              height_cm: '162.00',
              bmi: null,
              blood_pressure_sys: 118,
              blood_pressure_dia: 76,
              heart_rate: 72,
              temperature: '36.8',
              oxygen_saturation: '98',
              respiratory_rate: 16,
              recorded_at: '2026-08-16T14:05:00Z',
            },
          ],
        }),
      ]),
    );

    renderEncounter('/pacientes/12/atencion?appointment=1237');

    const weight = (await screen.findByLabelText('Peso (kg)')) as HTMLInputElement;
    await waitFor(() => expect(weight.value).toBe('68.40'));
    expect((screen.getByLabelText('Talla (cm)') as HTMLInputElement).value).toBe('162.00');
    expect((screen.getByLabelText('PA sistólica') as HTMLInputElement).value).toBe('118');
  });

  // Arriving by appointment must COMPLETE the nurse's draft — saving it as a
  // new record would leave the draft open forever and the visit twice.
  // Arriving by appointment must COMPLETE the nurse's draft — saving it as a
  // new record would leave the draft open forever and the visit twice.
  it('saves into the nurse draft instead of opening a second record', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(fetchPatientRecords).mockResolvedValue(
      page([makeRecord({ id: 91, status: 'vitals_done', appointment_id: 1237 })]),
    );
    vi.mocked(completeRecord).mockResolvedValue({ id: 91, appointment_id: 1237 } as never);

    renderEncounter('/pacientes/12/atencion?appointment=1237');

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Control de hipertensión');

    await waitFor(() => expect(completeRecord).toHaveBeenCalled());
    expect(vi.mocked(completeRecord).mock.calls.every((c) => c[0] === 91)).toBe(true);
    expect(vi.mocked(completeRecord).mock.calls[0][1]).toMatchObject({ finalize: true });
    expect(createRecord).not.toHaveBeenCalled();
  });

  // Typing fast must not beat the nurse-draft lookup: an early save would
  // open a second record for the same visit.
  it('waits for the nurse-draft lookup before the first save', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    let resolveLookup: (value: Paginated<MedicalRecord>) => void = () => {};
    vi.mocked(fetchPatientRecords).mockReturnValue(new Promise((resolve) => { resolveLookup = resolve; }));
    vi.mocked(completeRecord).mockResolvedValue({ id: 91, appointment_id: 1237 } as never);

    renderEncounter('/pacientes/12/atencion?appointment=1237');

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Control');
    await new Promise((resolve) => setTimeout(resolve, 150));
    expect(createRecord).not.toHaveBeenCalled();
    expect(completeRecord).not.toHaveBeenCalled();

    resolveLookup(page([makeRecord({ id: 91, status: 'vitals_done', appointment_id: 1237 })]));
    await waitFor(() => expect(completeRecord).toHaveBeenCalled());
    expect(vi.mocked(completeRecord).mock.calls[0][0]).toBe(91);
    expect(createRecord).not.toHaveBeenCalled();
  });

  // The atención saves itself as soon as it has a reason: the first save
  // registers the visit (finalized, so the server opens its attended
  // appointment), every later one updates that same record. `finalize:false`
  // would reopen a finished visit, so it must never be sent.
  it('saves itself once it has a reason and keeps updating the same record', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: 777 } as never);
    vi.mocked(completeRecord).mockResolvedValue({ id: 501, appointment_id: 777 } as never);

    renderEncounter();

    expect(await screen.findByText('Se guarda sola al escribir el motivo')).toBeTruthy();
    await user.type(screen.getByLabelText(/Motivo de atención/), 'Cefalea');

    await waitFor(() => expect(createRecord).toHaveBeenCalledTimes(1));
    expect(vi.mocked(createRecord).mock.calls[0][0]).toMatchObject({
      finalize: true,
      // Only the reason so far: saved, but flagged as incomplete.
      closed_with_override: true,
      reason_for_consultation: 'Cefalea',
    });
    expect(await screen.findByText('Atención #501')).toBeTruthy();
    expect(await screen.findByText(/^Guardado \d\d:\d\d$/)).toBeTruthy();

    await user.type(screen.getByLabelText(/Enfermedad actual/), 'Dos días');

    await waitFor(() =>
      expect(vi.mocked(completeRecord).mock.calls.at(-1)?.[1]).toMatchObject({ current_illness: 'Dos días' }),
    );
    expect(vi.mocked(completeRecord).mock.calls.every((c) => c[0] === 501)).toBe(true);
    expect(createRecord).toHaveBeenCalledTimes(1);
    const everyPayload = [
      ...vi.mocked(createRecord).mock.calls.map((c) => c[0]),
      ...vi.mocked(completeRecord).mock.calls.map((c) => c[1]),
    ];
    expect(everyPayload.every((p) => p.finalize === true)).toBe(true);
  });

  // Without a reason the server cannot open a visit, so nothing is sent — and
  // the guard warns, because leaving would lose what was typed.
  it('saves nothing and warns before leaving while there is no reason', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: null } as never);
    renderEncounter();

    await user.type(await screen.findByLabelText(/Enfermedad actual/), 'Dolor frontal');
    await new Promise((resolve) => setTimeout(resolve, 150));
    expect(createRecord).not.toHaveBeenCalled();
    expect(useEncounterGuardStore.getState().hasUnsavedWork).toBe(true);

    await user.type(screen.getByLabelText(/Motivo de atención/), 'Cefalea');
    await waitFor(() => expect(createRecord).toHaveBeenCalledTimes(1));
    expect(useEncounterGuardStore.getState().hasUnsavedWork).toBe(false);
  });

  // Leaving the patient unmounts the screen; edits the timer had not sent yet
  // must still reach the server.
  it('flushes pending edits when the screen goes away', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: 777 } as never);
    vi.mocked(completeRecord).mockResolvedValue({ id: 501, appointment_id: 777 } as never);

    const { unmount } = renderEncounter();

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Cefalea');
    await screen.findByText('Atención #501');

    encounterTiming.autosaveDelayMs = 60_000;
    await user.type(screen.getByLabelText(/Enfermedad actual/), 'Antes de salir');
    unmount();

    await waitFor(() =>
      expect(vi.mocked(completeRecord).mock.calls.at(-1)).toEqual([
        501,
        expect.objectContaining({ current_illness: 'Antes de salir' }),
      ]),
    );
  });

  // «Editar receta» lands on the Prescripción tab of that same visit, so fixing
  // a forgotten medication never looks like starting a new consultation.
  it('opens straight on the Prescripción tab with ?tab=rx', async () => {
    actAs(['records.manage']);
    vi.mocked(fetchRecord).mockResolvedValue(
      makeRecord({ id: 641, status: 'completed', reason_for_consultation: 'Control', treatment: 'Reposo' }) as never,
    );

    renderEncounter('/pacientes/12/atencion?record=641&tab=rx');

    expect(await screen.findByText('Atención #641')).toBeTruthy();
    expect(screen.getByRole('tab', { name: /^Receta/ }).getAttribute('aria-selected')).toBe('true');
    expect((await screen.findByLabelText(/Plan terapéutico/) as HTMLTextAreaElement).value).toBe('Reposo');
  });

  // Resuming after a reload or from the history: the whole visit comes back,
  // not only the vitals, and later edits update it instead of creating another.
  it('resumes a saved visit from ?record= with everything it held', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(fetchRecord).mockResolvedValue(
      makeRecord({
        id: 640,
        status: 'completed',
        appointment_id: 900,
        reason_for_consultation: 'Dolor lumbar',
        current_illness: 'Tres días, sin trauma',
        treatment: 'Reposo relativo',
        diagnoses: [{ id: 1, cie10_code: 'M54.5', cie10_description: 'Lumbago no especificado', type: 'primary' }],
        prescriptions: [
          {
            id: 2, medication_name: 'Ibuprofeno', generic_name: null, concentration: '400 mg', dosage: null,
            route: null, frequency: null, duration_days: null, quantity: 10, instructions: 'Cada 8 horas', is_controlled: false,
          },
        ],
      } as Partial<MedicalRecord> & { id: number; status: RecordStatus }) as never,
    );
    vi.mocked(completeRecord).mockResolvedValue({ id: 640, appointment_id: 900 } as never);

    renderEncounter('/pacientes/12/atencion?record=640');

    expect(await screen.findByText('Atención #640')).toBeTruthy();
    expect((screen.getByLabelText(/Motivo de atención/) as HTMLTextAreaElement).value).toBe('Dolor lumbar');
    expect((screen.getByLabelText(/Enfermedad actual/) as HTMLTextAreaElement).value).toBe('Tres días, sin trauma');
    // The summary shows what the visit already carries.
    const summary = screen.getByRole('complementary', { name: 'Resumen de la atención' });
    expect(within(summary).getByText('M54.5')).toBeTruthy();
    expect(within(summary).getByText(/Ibuprofeno/)).toBeTruthy();
    await user.click(screen.getByRole('tab', { name: /^Receta/ }));
    expect((screen.getByLabelText(/Plan terapéutico/) as HTMLTextAreaElement).value).toBe('Reposo relativo');
    // Freshly loaded is not "changed": nothing is written back on its own.
    await new Promise((resolve) => setTimeout(resolve, 150));
    expect(completeRecord).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText(/Plan terapéutico/), ' y calor local');
    await waitFor(() => expect(completeRecord).toHaveBeenCalled());
    expect(vi.mocked(completeRecord).mock.calls.at(-1)?.[0]).toBe(640);
    expect(vi.mocked(completeRecord).mock.calls.at(-1)?.[1]).toMatchObject({
      treatment: 'Reposo relativo y calor local',
      closed_with_override: false,
    });
    expect(createRecord).not.toHaveBeenCalled();
  });

  // A visit saved by mistake (the wrong patient) can be voided — with a
  // reason, and nothing writes to it afterwards.
  it('voids a saved visit with a reason', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: 777 } as never);
    vi.mocked(completeRecord).mockResolvedValue({ id: 501, appointment_id: 777 } as never);
    vi.mocked(voidRecord).mockResolvedValue(undefined);

    renderEncounter();

    const summary = await screen.findByRole('complementary', { name: 'Resumen de la atención' });
    expect(within(summary).queryByRole('button', { name: /Anular atención/ })).toBeNull();
    await user.type(screen.getByLabelText(/Motivo de atención/), 'Cefalea');
    await screen.findByText('Atención #501');

    await user.click(within(summary).getByRole('button', { name: /Anular atención/ }));
    const dialog = screen.getByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Anular atención' }));
    expect(voidRecord).not.toHaveBeenCalled();
    expect(within(dialog).getByText('Escribe por qué se anula.')).toBeTruthy();

    await user.type(within(dialog).getByLabelText(/Motivo de la anulación/), 'Paciente equivocado');
    await user.click(within(dialog).getByRole('button', { name: 'Anular atención' }));

    await waitFor(() => expect(voidRecord).toHaveBeenCalledWith(501, 'Paciente equivocado'));
    await waitFor(() => expect(useUiStore.getState().toasts.some((t) => t.message === 'Atención anulada.')).toBe(true));
  });

  it('asks for the reason instead of finishing a visit without one', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    renderEncounter();

    await user.click(await screen.findByRole('button', { name: /Terminar atención/ }));

    expect(createRecord).not.toHaveBeenCalled();
    expect(screen.getByText('Escribe el motivo para guardar la atención.')).toBeTruthy();
    expect(document.activeElement).toBe(screen.getByLabelText(/Motivo de atención/));
  });

  // 2026-10-23: a hook placed below the closure screen's early return crashed
  // «Terminar atención» into the error boundary. Finishing must reach the screen.
  it('finishes a visit onto the closure screen without crashing', async () => {
    const user = userEvent.setup();
    actAs(['records.manage', 'assistant.use']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: null } as never);
    vi.mocked(completeRecord).mockResolvedValue({ id: 501, appointment_id: null } as never);
    renderEncounter();

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Dolor de garganta');
    await user.click(screen.getByRole('button', { name: /Terminar atención/ }));

    expect(await screen.findByText(/terminada/)).toBeTruthy();
    expect(screen.queryByText('Algo salió mal')).toBeNull();
  });

  // The PDF comes from the server, so it is saved first — into the visit that
  // already exists, never a new one (the orphan-record bug of 2026-09).
  it('saves into the nurse draft before printing the receta, without opening a second record', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(fetchPatientRecords).mockResolvedValue(
      page([makeRecord({ id: 91, status: 'vitals_done', appointment_id: 1237 })]),
    );
    vi.mocked(completeRecord).mockResolvedValue({ id: 91, appointment_id: 1237 } as never);
    vi.mocked(downloadPrescriptionPdf).mockResolvedValue(new Blob(['%PDF-1.4']) as never);

    renderEncounter('/pacientes/12/atencion?appointment=1237');

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Dermatitis de contacto');
    await user.click(screen.getByRole('tab', { name: /^Receta/i }));
    await user.click(screen.getByRole('button', { name: /Añadir medicamento/i }));
    await user.type(screen.getByLabelText('Nombre *'), 'Betametasona crema');
    await user.type(screen.getByLabelText('Cantidad a dispensar *'), '1');

    await user.click(screen.getAllByRole('button', { name: /Imprimir receta/i })[0]);

    await waitFor(() => expect(downloadPrescriptionPdf).toHaveBeenCalledWith(91));
    expect(vi.mocked(completeRecord).mock.calls.every((c) => c[0] === 91)).toBe(true);
    expect(createRecord).not.toHaveBeenCalled();
  });

  it('re-syncs by ?draft= too, without opening a second record', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(fetchRecord).mockResolvedValue(
      makeRecord({ id: 77, status: 'draft', appointment_id: null }) as never,
    );
    vi.mocked(completeRecord).mockResolvedValue({ id: 77, appointment_id: null } as never);
    vi.mocked(downloadPrescriptionPdf).mockResolvedValue(new Blob(['%PDF-1.4']) as never);

    renderEncounter('/pacientes/12/atencion?draft=77');

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Dermatitis de contacto');
    await user.click(screen.getByRole('tab', { name: /^Receta/i }));
    await user.click(screen.getByRole('button', { name: /Añadir medicamento/i }));
    await user.type(screen.getByLabelText('Nombre *'), 'Betametasona crema');
    await user.type(screen.getByLabelText('Cantidad a dispensar *'), '1');

    await user.click(screen.getAllByRole('button', { name: /Imprimir receta/i })[0]);

    await waitFor(() => expect(downloadPrescriptionPdf).toHaveBeenCalledWith(77));
    expect(vi.mocked(completeRecord).mock.calls.every((c) => c[0] === 77)).toBe(true);
    expect(createRecord).not.toHaveBeenCalled();
  });

  // "The button does nothing": the quantity was missing and the save came back
  // 422. The guard says what is missing before the receta is requested.
  it('says what is missing instead of failing silently when a quantity is blank', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: null } as never);

    renderEncounter();

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Dermatitis de contacto');
    await user.click(screen.getByRole('tab', { name: /^Receta/i }));
    await user.click(screen.getByRole('button', { name: /Añadir medicamento/i }));
    await user.type(screen.getByLabelText('Nombre *'), 'Betametasona crema');

    await user.click(screen.getAllByRole('button', { name: /Imprimir receta/i })[0]);

    await waitFor(() =>
      expect(
        useUiStore.getState().toasts.some((t) => /cantidad a dispensar/i.test(t.message)),
      ).toBe(true),
    );
    expect(downloadPrescriptionPdf).not.toHaveBeenCalled();
  });

  // When the server rejects it, the doctor reads WHY — once — and the button
  // does not stay stuck on «Generando…». The autosave failing quietly first
  // shows in the status, not as a toast.
  it('surfaces the server message once and leaves the receta button usable', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(createRecord).mockRejectedValue(
      Object.assign(new Error('Request failed'), {
        isAxiosError: true,
        response: { status: 422, data: { message: 'El nombre del medicamento es obligatorio.' } },
      }) as never,
    );

    renderEncounter();

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Dermatitis de contacto');
    await user.click(screen.getByRole('tab', { name: /^Receta/i }));
    await user.click(screen.getByRole('button', { name: /Añadir medicamento/i }));
    await user.type(screen.getByLabelText('Nombre *'), 'Betametasona crema');
    await user.type(screen.getByLabelText('Cantidad a dispensar *'), '1');
    await waitFor(() => expect(screen.getByText('No se pudo guardar')).toBeTruthy());
    expect(useUiStore.getState().toasts).toHaveLength(0);

    await user.click(screen.getAllByRole('button', { name: /Imprimir receta/i })[0]);

    await waitFor(() =>
      expect(
        useUiStore.getState().toasts.some((t) => t.message === 'El nombre del medicamento es obligatorio.'),
      ).toBe(true),
    );
    await waitFor(() => expect(screen.queryByText('Generando…')).toBeNull());
    expect(screen.getAllByRole('button', { name: /Imprimir receta/i })[0]).toHaveProperty('disabled', false);
    expect(useUiStore.getState().toasts).toHaveLength(1);
    expect(downloadPrescriptionPdf).not.toHaveBeenCalled();
  });

  it('never adopts a closed visit as the one being attended', async () => {
    vi.mocked(fetchPatientRecords).mockResolvedValue(
      page([
        makeRecord({
          id: 91,
          status: 'completed',
          appointment_id: 1237,
          vitals: [
            {
              id: 5,
              weight_kg: '68.40',
              height_cm: '162.00',
              bmi: null,
              blood_pressure_sys: null,
              blood_pressure_dia: null,
              heart_rate: null,
              temperature: null,
              oxygen_saturation: null,
              respiratory_rate: null,
              recorded_at: '2026-08-16T14:05:00Z',
            },
          ],
        }),
      ]),
    );

    renderEncounter('/pacientes/12/atencion?appointment=1237');

    const weight = (await screen.findByLabelText('Peso (kg)')) as HTMLInputElement;
    await waitFor(() => expect(fetchPatientRecords).toHaveBeenCalled());
    expect(weight.value).toBe('');
  });

  it('gives the general consultation its exam segments', async () => {
    renderEncounter();

    expect(await screen.findByRole('heading', { name: /Exploración física por segmentos/i })).toBeTruthy();
    expect(screen.getByLabelText('Abdomen')).toBeTruthy();
  });

  // `buildContent` used to filter prescriptions on `dosage && frequency`. Those
  // stopped being captured on 2026-09-21, so that filter would drop every new
  // prescription and the doctor would see "guardado" with no prescription.
  it('sends the three-field prescription to the server instead of filtering it out', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(createRecord).mockResolvedValue({ id: 501, appointment_id: null } as never);
    vi.mocked(completeRecord).mockResolvedValue({ id: 501, appointment_id: null } as never);

    renderEncounter();

    await user.type(await screen.findByLabelText(/Motivo de atención/), 'Dermatitis de contacto');
    await user.click(screen.getByRole('tab', { name: /^Receta/i }));
    await user.click(screen.getByRole('button', { name: /Añadir medicamento/i }));
    await user.type(screen.getByLabelText('Nombre *'), 'Betametasona crema');
    await user.type(screen.getByLabelText('Cantidad a dispensar *'), '1');
    await user.type(screen.getByLabelText('Indicaciones al paciente'), 'Aplicar dos veces al día.');

    const sent = () => [
      ...vi.mocked(createRecord).mock.calls.map((c) => c[0]),
      ...vi.mocked(completeRecord).mock.calls.map((c) => c[1]),
    ].filter((p) => p.prescriptions?.[0]?.instructions === 'Aplicar dos veces al día.');
    await waitFor(() => expect(sent()).not.toHaveLength(0));
    const payload = sent()[0];
    expect(payload.prescriptions).toHaveLength(1);
    expect(payload.prescriptions![0]).toMatchObject({
      medication_name: 'Betametasona crema',
      quantity: 1,
      instructions: 'Aplicar dos veces al día.',
    });
    expect(payload.prescriptions![0].dosage).toBeUndefined();
    expect(payload.prescriptions![0].frequency).toBeUndefined();
  });

  it('keeps the reason, the evolution and patient data beside every work section', async () => {
    const user = userEvent.setup();
    renderEncounter();

    expect(await screen.findByRole('region', { name: 'Datos de la atención' })).toBeTruthy();
    expect(screen.getByRole('region', { name: 'Mesa de trabajo' })).toBeTruthy();
    expect(screen.getByRole('complementary', { name: 'Resumen de la atención' })).toBeTruthy();
    await user.click(screen.getByRole('tab', { name: /^Receta/i }));

    expect(screen.getByLabelText('Tipo de consulta')).toBeTruthy();
    expect(screen.getByLabelText('Peso (kg)')).toBeTruthy();
    expect(screen.getByLabelText(/Motivo de atención/)).toBeTruthy();
    expect(screen.getByLabelText(/Evolución/)).toBeTruthy();
    expect(screen.getByLabelText(/Enfermedad actual/)).toBeTruthy();
    expect(screen.getByRole('button', { name: /Antecedentes del paciente/i })).toBeTruthy();
  });

  it('connects the section tabs to a keyboard-operable work panel', async () => {
    const user = userEvent.setup();
    renderEncounter();
    const evaluation = await screen.findByRole('tab', { name: /Exploración/i });
    const diagnosis = screen.getByRole('tab', { name: /Diagnóstico/i });
    expect(evaluation.getAttribute('aria-controls')).toBeTruthy();
    expect(screen.getByRole('tabpanel', { name: /Exploración/i })).toBeTruthy();

    evaluation.focus();
    await user.keyboard('{ArrowRight}');
    expect(document.activeElement).toBe(diagnosis);
    expect(diagnosis.getAttribute('aria-selected')).toBe('true');
    const panel = screen.getByRole('tabpanel', { name: /Diagnóstico/i });
    await user.keyboard('{Tab}');
    expect(document.activeElement).toBe(panel);
    await user.keyboard('{Shift>}{Tab}{/Shift}');
    expect(document.activeElement).toBe(diagnosis);
  });

  // «Iniciar control prenatal» from the gyneco-obstetric file lands on the
  // prenatal template — and an ineligible doctor still falls back to general.
  it('opens on the template the gyneco file asked for', async () => {
    actAs([], 'personal', ['gyneco_obstetrics']);
    renderEncounter('/pacientes/12/atencion?tipo=prenatal');
    expect(((await screen.findByLabelText('Tipo de consulta')) as HTMLSelectElement).value).toBe('prenatal');
    cleanup();

    actAs([], 'personal', []);
    renderEncounter('/pacientes/12/atencion?tipo=prenatal');
    await waitFor(async () =>
      expect(((await screen.findByLabelText('Tipo de consulta')) as HTMLSelectElement).value).toBe('general'),
    );
  });

  it('keeps patient alerts and pregnancy context when the expediente rail is hidden', async () => {
    actAs([], 'personal', ['gyneco_obstetrics']);
    workspaceContext = {
      patient: {
        ...patient,
        alerts: [{
          id: 7, patient_id: 12, text: 'Riesgo obstétrico', severity: 'critical',
          severity_label: 'Crítica', resolved_at: null, is_active: true, created_at: null,
        }],
        active_pregnancy: {
          id: 8, patient_id: 12, status: 'active', status_label: 'En curso',
          gestational_age: { is_valid: false },
        } as NonNullable<Patient['active_pregnancy']>,
      },
      patientId: 12,
    };

    renderEncounter();
    expect(await screen.findByText('Riesgo obstétrico')).toBeTruthy();
    expect(screen.getByText('Embarazo sin datar')).toBeTruthy();
  });
});

describe('EncounterSection — Elo', () => {
  async function resumeWithDiagnosis() {
    vi.mocked(fetchRecord).mockResolvedValue(
      makeRecord({
        id: 641,
        status: 'completed',
        reason_for_consultation: 'Dolor de garganta',
        diagnoses: [{ id: 1, cie10_code: 'J03.9', cie10_description: 'Amigdalitis aguda', type: 'primary' }],
        prescriptions: [],
      } as Partial<MedicalRecord> & { id: number; status: RecordStatus }) as never,
    );
    renderEncounter('/pacientes/12/atencion?record=641');
    await screen.findByText('Atención #641');
  }

  it('sends Elo the clinical picture, never the name or the cédula', async () => {
    const user = userEvent.setup();
    const { suggestPrescription } = await import('@/api/elo');
    vi.mocked(suggestPrescription).mockResolvedValue({ items: [], reason: 'Sin medicación.', removed_for_allergy: [], disclaimer: '' });
    actAs(['records.manage', 'assistant.use']);
    await resumeWithDiagnosis();

    await user.click(screen.getByRole('button', { name: /Sugerir receta/ }));

    const sent = vi.mocked(suggestPrescription).mock.calls[0][0];
    expect(sent).toMatchObject({ age: 38, sex: 'female', reason: 'Dolor de garganta', diagnoses: [{ code: 'J03.9', description: 'Amigdalitis aguda' }] });
    const body = JSON.stringify(sent);
    expect(body).not.toContain('María López');
    expect(body).not.toContain('1712345678');
    expect(body).not.toContain('maria@example.com');
  });

  it('does not show Elo to whom cannot use the assistant', async () => {
    actAs(['records.manage']);
    await resumeWithDiagnosis();

    expect(screen.queryByRole('region', { name: 'Elo, tu asistente' })).toBeNull();
  });

  it('what Elo heard fills motivo, enfermedad actual and evolución', async () => {
    const user = userEvent.setup();
    let recognition: { onresult: ((e: unknown) => void) | null; onend: (() => void) | null } | null = null;
    (window as unknown as { webkitSpeechRecognition: unknown }).webkitSpeechRecognition = class {
      onresult = null;
      onerror = null;
      onend: (() => void) | null = null;
      constructor() {
        recognition = this as never;
      }
      start() {}
      stop() {
        this.onend?.();
      }
      abort() {}
    };
    const { suggestFromDictation, fetchEloAgents } = await import('@/api/elo');
    vi.mocked(fetchEloAgents).mockResolvedValue([
      { key: 'dictation', name: 'Escuchar la consulta', description: '', available: true, enabled: true, rules_count: 0, accepted_rate: null },
    ]);
    vi.mocked(suggestFromDictation).mockResolvedValue({
      reason: 'Control de amigdalitis',
      illness: 'Odinofagia que mejoró.',
      evolution: 'Afebril desde hace dos días con el antibiótico.',
      exam: '',
      diagnoses: [],
      dropped_codes: 0, why: '', disclaimer: '',
    });
    actAs(['records.manage', 'assistant.use']);
    await resumeWithDiagnosis();

    await user.click(await screen.findByRole('button', { name: /Escuchar con Elo/ }));
    act(() => recognition?.onresult?.({ resultIndex: 0, results: [Object.assign([{ transcript: 'ya no tengo fiebre doctor' }], { isFinal: true })] }));
    await user.click(screen.getByRole('button', { name: /Detener y ordenar/ }));
    await user.click(await screen.findByRole('button', { name: /Aceptar/ }));
    // The motivo was already written: replace it.
    await user.click(screen.getByRole('button', { name: 'Reemplazar' }));

    expect((screen.getByLabelText('Motivo de atención *') as HTMLTextAreaElement).value).toBe('Control de amigdalitis');
    expect((screen.getByLabelText('Enfermedad actual') as HTMLTextAreaElement).value).toBe('Odinofagia que mejoró.');
    expect((screen.getByLabelText('Evolución') as HTMLTextAreaElement).value).toBe('Afebril desde hace dos días con el antibiótico.');
    delete (window as unknown as { webkitSpeechRecognition?: unknown }).webkitSpeechRecognition;
  });

  it('does not offer to listen until the doctor turns it on in Ajustes', async () => {
    (window as unknown as { webkitSpeechRecognition: unknown }).webkitSpeechRecognition = class {};
    actAs(['records.manage', 'assistant.use']);
    await resumeWithDiagnosis();

    await screen.findByRole('region', { name: 'Elo, tu asistente' });
    expect(screen.queryByRole('button', { name: /Escuchar con Elo/ })).toBeNull();
    delete (window as unknown as { webkitSpeechRecognition?: unknown }).webkitSpeechRecognition;
  });

  it('a tidy note fills the enfermedad actual and adds only new CIE-10 codes', async () => {
    const user = userEvent.setup();
    const { suggestNote } = await import('@/api/elo');
    vi.mocked(suggestNote).mockResolvedValue({
      illness: 'Odinofagia de tres días.',
      diagnoses: [
        { code: 'J03.9', description: 'AMIGDALITIS AGUDA, NO ESPECIFICADA', type: 'primary' },
        { code: 'R50.9', description: 'FIEBRE, NO ESPECIFICADA', type: 'secondary' },
      ],
      dropped_codes: 0, reason: '', disclaimer: '',
    });
    actAs(['records.manage', 'assistant.use']);
    await resumeWithDiagnosis();

    await user.click(screen.getByRole('button', { name: /Ordenar nota/ }));
    await user.click(await screen.findByRole('button', { name: /Editar/ }));

    // Editar opens the diagnosis step: R50.9 added, J03.9 not repeated.
    expect(await screen.findByText(/FIEBRE, NO ESPECIFICADA/)).toBeTruthy();
    expect(screen.queryByText(/AMIGDALITIS AGUDA, NO ESPECIFICADA/)).toBeNull();
  });

  it('proposed exams fill the order, and the doctor still generates it', async () => {
    const user = userEvent.setup();
    const { suggestExams } = await import('@/api/elo');
    const { createPatientOrder } = await import('@/api/studyOrders');
    vi.mocked(suggestExams).mockResolvedValue({
      type: 'laboratory',
      items: [{ name: 'Biometría hemática completa', study_id: 1, category: 'Hematológico', in_catalog: true }],
      clinical_info: 'Amigdalitis aguda febril', reason: '', disclaimer: '',
    });
    actAs(['records.manage', 'assistant.use']);
    await resumeWithDiagnosis();

    await user.click(screen.getByRole('button', { name: /Sugerir exámenes/ }));
    await user.click(await screen.findByRole('button', { name: /Llevar a la orden/ }));

    expect(await screen.findByDisplayValue('Amigdalitis aguda febril')).toBeTruthy();
    expect(screen.getAllByText('Biometría hemática completa').length).toBeGreaterThan(0);
    expect(createPatientOrder).not.toHaveBeenCalled();
  });

  it('suggests CIE-10 in the diagnosis search only when asked, and a chip adds it', async () => {
    const user = userEvent.setup();
    const { suggestNote } = await import('@/api/elo');
    vi.mocked(suggestNote).mockResolvedValue({
      illness: '', dropped_codes: 0, reason: '', disclaimer: '',
      diagnoses: [{ code: 'R50.9', description: 'FIEBRE, NO ESPECIFICADA', type: 'secondary' }],
    });
    actAs(['records.manage', 'assistant.use']);
    await resumeWithDiagnosis();

    await user.click(screen.getByRole('tab', { name: /Diagnóstico/ }));
    expect(suggestNote).not.toHaveBeenCalled();
    await user.click(screen.getByRole('button', { name: /Sugerir con Elo/ }));
    await user.click(await screen.findByRole('button', { name: /R50.9/ }));

    expect(screen.queryByRole('button', { name: /R50.9/ })).toBeNull();
    expect(screen.getAllByText(/FIEBRE, NO ESPECIFICADA/).length).toBeGreaterThan(0);
  });
});
