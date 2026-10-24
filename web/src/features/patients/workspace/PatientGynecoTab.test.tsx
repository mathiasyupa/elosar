// @vitest-environment jsdom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter, Route, Routes, useLocation } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useAuthStore } from '@/store/auth';
import type { GynecoHistory, Patient, Pregnancy, PrenatalScreening, Workspace } from '@/api/types';
import { PatientGynecoTab, gynecoTiming } from './PatientGynecoTab';

vi.mock('@/api/obstetrics', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/obstetrics')>()),
  fetchGynecoHistory: vi.fn(),
  fetchPregnancies: vi.fn(),
  saveGynecoHistory: vi.fn(),
  fetchScreenings: vi.fn(),
  saveScreening: vi.fn(),
  // The calendar and the blood-pressure chart read the timeline: never the network.
  fetchPregnancyTimeline: vi.fn().mockResolvedValue([]),
}));
vi.mock('@/features/obstetrics/PregnancyCurve', () => ({ PregnancyCurve: () => <div>Curva</div> }));
vi.mock('./context', () => ({
  usePatientWorkspace: () => ({ patient: { id: 25, name: 'Gestante Prueba' } as Patient, patientId: 25 }),
}));

const { fetchGynecoHistory, fetchPregnancies, saveGynecoHistory, fetchScreenings } = await import('@/api/obstetrics');

function historyData(over: Partial<GynecoHistory> = {}): GynecoHistory {
  return {
    id: 1,
    patient_id: 25,
    menarche_age: 12,
    last_pap_at: '2023-05-10',
    declared: {
      gestations: 3, term_births: 1, preterm_births: 0, cesareans: 1,
      abortions: 1, ectopics: 0, stillbirths: 0, living_children: 2,
    } as GynecoHistory['declared'],
    registered_in_elosar: {
      gestations: 1, term_births: 0, preterm_births: 0, cesareans: 0,
      abortions: 0, ectopics: 0, stillbirths: 0, ongoing: 1,
    },
    notes: null,
    ...over,
  };
}

function pregnancy(over: Partial<Pregnancy> = {}): Pregnancy {
  return {
    id: 7,
    patient_id: 25,
    status: 'active',
    status_label: 'En curso',
    lmp_date: '2026-04-20',
    dating_source: 'lmp',
    edd_date: '2027-01-25',
    elective_cesarean_date: '2027-01-18',
    edd_protocol: 'NAEGELE-280',
    risk_notes: 'Antecedente de preeclampsia',
    dating: { edd_by_lmp: '2027-01-25', edd_by_ultrasound: null, discrepancy_days: null, source_used: 'lmp', missing: [] },
    gestational_age: {
      days: 171, weeks: 24, remainder_days: 3, label: '24+3',
      trimester: 2, is_term: false, is_post_term: false, is_valid: true,
    },
    fetus_count: 1,
    ...over,
  } as Pregnancy;
}

function screening(over: Partial<PrenatalScreening>): PrenatalScreening {
  return {
    code: 'x', label: 'Examen', when: 'Primer control', universal: true,
    result: null, result_at: null, notes: null, recorded: false, ...over,
  };
}

function actAs(permissions: string[]) {
  useAuthStore.setState({
    activeWorkspaceId: 1,
    workspaces: [{ id: 1, name: 'Consultorio', type: 'personal', is_clinic: false, permissions, roles: [] } as unknown as Workspace],
  });
}

function Where() {
  const location = useLocation();
  return <p data-testid="where">{location.pathname + location.search}</p>;
}

function renderTab() {
  const qc = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={qc}>
      <MemoryRouter initialEntries={['/pacientes/25/gineco']}>
        <Routes>
          <Route path="/pacientes/:patientId/gineco" element={<PatientGynecoTab />} />
          <Route path="/pacientes/:patientId/atencion" element={<Where />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  gynecoTiming.autosaveDelayMs = 30;
  vi.mocked(fetchGynecoHistory).mockResolvedValue(historyData());
  vi.mocked(fetchPregnancies).mockResolvedValue([pregnancy()]);
  vi.mocked(saveGynecoHistory).mockResolvedValue(historyData() as never);
  vi.mocked(fetchScreenings).mockResolvedValue([
    screening({ code: 'hb', label: 'Hemoglobina', when: 'Primer control' }),
    screening({ code: 'vih', label: 'VIH', when: 'Primer y tercer trimestre', recorded: true, result: 'No reactivo' }),
    screening({ code: 'ttog', label: 'TTOG', when: 'Según riesgo', universal: false }),
  ]);
});

afterEach(async () => {
  cleanup();
  // The unmount flush sends on a later tick; let it land before clearing, or it
  // would count in the next test.
  await new Promise((resolve) => setTimeout(resolve, 0));
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, workspaces: [] });
});

describe('PatientGynecoTab — one screen, nothing lost', () => {
  it('shows the ficha, the pregnancy and the pending column together', async () => {
    actAs(['records.manage', 'records.view']);
    renderTab();

    expect(await screen.findByRole('region', { name: 'Ficha gíneco-obstétrica' })).toBeTruthy();
    expect(screen.getByRole('region', { name: 'Embarazo' })).toBeTruthy();
    expect(screen.getByRole('complementary', { name: 'Pendientes y acciones' })).toBeTruthy();
    // No nested menu any more.
    expect(screen.queryByRole('tablist')).toBeNull();
  });

  it('keeps every field of the history and the obstetric formula, labelled', async () => {
    actAs(['records.manage']);
    renderTab();

    for (const label of [
      'Menarquia (edad)', 'IVSA (edad)', 'Ciclo: cada cuántos días', 'Ciclo: cuántos días dura',
      'Ciclo regular', 'Dismenorrea', 'Método de planificación', 'Notas de planificación',
      'Último Papanicolaou', 'Resultado del Papanicolaou', 'Última mamografía', 'Resultado de la mamografía',
      'Menopausia', 'Fecha de menopausia', 'Notas',
      'Gestas', 'Partos a término', 'Partos pretérmino', 'Cesáreas', 'Abortos', 'Ectópicos', 'Óbitos', 'Hijos vivos',
    ]) {
      expect(await screen.findByLabelText(label)).toBeTruthy();
    }
    expect((screen.getByLabelText('Gestas') as HTMLInputElement).value).toBe('3');
    expect(screen.getByText(/Registrados en Elosar/)).toBeTruthy();
  });

  it('saves the ficha by itself, without a Guardar button', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    renderTab();

    expect(screen.queryByRole('button', { name: /Guardar historia/ })).toBeNull();
    const menarche = await screen.findByLabelText('Menarquia (edad)');
    await user.clear(menarche);
    await user.type(menarche, '13');

    await waitFor(() =>
      expect(vi.mocked(saveGynecoHistory).mock.calls.at(-1)).toEqual([25, expect.objectContaining({ menarche_age: 13, gestations: 3 })]),
    );
    expect(await screen.findByText('Guardada')).toBeTruthy();
  });

  it('does not write anything back just for opening the ficha', async () => {
    actAs(['records.manage']);
    renderTab();
    await screen.findByLabelText('Menarquia (edad)');
    await new Promise((resolve) => setTimeout(resolve, 120));
    expect(saveGynecoHistory).not.toHaveBeenCalled();
  });

  it('shows the gestational age, EDD and elective cesarean date large, plus the carnet and curve', async () => {
    actAs(['records.manage']);
    renderTab();

    const main = await screen.findByRole('region', { name: 'Embarazo' });
    expect(within(main).getByText('24+3')).toBeTruthy();
    expect(within(main).getByText('Cesárea electiva (39 sem.)')).toBeTruthy();
    expect(within(main).getByText('Curva')).toBeTruthy();
    expect(await within(main).findByLabelText('Resultado de Hemoglobina')).toBeTruthy();
  });

  it('lists what the carnet is missing — universal exams only — and the risk', async () => {
    actAs(['records.manage']);
    renderTab();

    const pending = await screen.findByRole('complementary', { name: 'Pendientes y acciones' });
    expect(within(pending).getByText('Antecedente de preeclampsia')).toBeTruthy();
    expect(await within(pending).findByText('1 examen sin registrar')).toBeTruthy();
    expect(within(pending).getByText('Hemoglobina')).toBeTruthy();
    // Recorded and "según riesgo" exams are not pending.
    expect(within(pending).queryByText('VIH')).toBeNull();
    expect(within(pending).queryByText('TTOG')).toBeNull();
    expect(within(pending).getByText(/hace \d+ años?/)).toBeTruthy();
  });

  it('starts the prenatal control in the atención, already on the prenatal template', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    renderTab();

    await user.click(await screen.findByRole('button', { name: /Iniciar control prenatal/ }));
    expect(screen.getByTestId('where').textContent).toBe('/pacientes/25/atencion?tipo=prenatal');
  });

  it('keeps dating and outcome for the active pregnancy, in the pending column', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    renderTab();

    const pending = await screen.findByRole('complementary', { name: 'Pendientes y acciones' });
    await user.click(within(pending).getByRole('button', { name: /Editar datación/ }));
    expect(screen.getByRole('dialog', { name: 'Datación del embarazo' })).toBeTruthy();
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancelar' }));

    await user.click(within(pending).getByRole('button', { name: /Registrar desenlace/ }));
    expect(screen.getByRole('dialog', { name: 'Desenlace del embarazo' })).toBeTruthy();
  });

  it('offers registering a pregnancy and a gynecological visit when none is active', async () => {
    const user = userEvent.setup();
    actAs(['records.manage']);
    vi.mocked(fetchPregnancies).mockResolvedValue([pregnancy({ id: 3, status: 'completed', status_label: 'Terminado', outcome_label: 'Parto a término' } as Partial<Pregnancy>)]);
    renderTab();

    expect(await screen.findByText('Sin embarazo activo registrado.')).toBeTruthy();
    expect(screen.getByRole('region', { name: 'Embarazos previos' })).toBeTruthy();
    const pending = screen.getByRole('complementary', { name: 'Pendientes y acciones' });
    await user.click(within(pending).getByRole('button', { name: /Registrar embarazo/ }));
    expect(screen.getByRole('dialog', { name: 'Registrar embarazo' })).toBeTruthy();
    await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Cancelar' }));

    await user.click(within(pending).getByRole('button', { name: /Iniciar consulta ginecológica/ }));
    expect(screen.getByTestId('where').textContent).toBe('/pacientes/25/atencion?tipo=gynecological');
  });

  it('is read-only without records.manage', async () => {
    actAs(['records.view']);
    renderTab();

    expect(((await screen.findByLabelText('Menarquia (edad)')) as HTMLInputElement).disabled).toBe(true);
    expect(screen.queryByRole('button', { name: /Iniciar control prenatal/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Editar datación/ })).toBeNull();
  });
});
