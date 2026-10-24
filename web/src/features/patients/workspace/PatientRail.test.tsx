// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { PatientRail } from './PatientRail';
import { fetchPatientRecords } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { useEncounterGuardStore } from '@/store/encounterGuard';
import type { ClinicalModule, MedicalRecord, Paginated, Patient, RecordStatus, Workspace } from '@/api/types';

vi.mock('@/api/patients', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@/api/patients')>()),
  fetchPatientRecords: vi.fn(),
}));

function page(records: MedicalRecord[]): Paginated<MedicalRecord> {
  return {
    data: records,
    links: { first: null, last: null, prev: null, next: null },
    meta: { current_page: 1, from: null, last_page: 1, per_page: 20, to: null, total: records.length },
  };
}

function record(status: RecordStatus): MedicalRecord {
  return {
    id: 9, appointment_id: null, patient_id: 42, created_at: '2026-08-16T14:00:00Z',
    reason_for_consultation: null, current_illness: null, physical_examination: null,
    review_of_systems: null, pain_scale: null, diagnosis: null, treatment: null,
    follow_up_date: null, follow_up_notes: null, status,
  };
}

beforeEach(() => {
  vi.mocked(fetchPatientRecords).mockResolvedValue(page([]));
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  useAuthStore.setState({ activeWorkspaceId: null, doctor: null, workspaces: [] });
  useEncounterGuardStore.setState({ hasUnsavedWork: false });
});

function actAs(permissions: string[], clinicalModules: ClinicalModule[] = []) {
  useAuthStore.setState({
    activeWorkspaceId: 1,
    doctor: {
      id: 9,
      name: 'Dra. Prueba',
      id_number: null,
      email: null,
      phone: null,
      specialty: 'Medicina General',
      clinical_modules: clinicalModules,
    },
    workspaces: [
      { id: 1, name: 'Consultorio', type: 'personal', is_clinic: false, permissions, roles: [] } as unknown as Workspace,
    ],
  });
}

function patient(over: Partial<Patient> = {}): Patient {
  return {
    id: 42,
    name: 'Paciente Prueba',
    id_number: '1710000017',
    birth_date: '1990-01-01',
    age: 36,
    gender: 'female',
    phone: null,
    email: null,
    city: null,
    address: null,
    ...over,
  };
}

function renderRail(p: Patient, entry = '/') {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[entry]}>
        <PatientRail patient={p} />
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

describe('PatientRail — Atención', () => {
  it('reveals the active section after the patient workspace remounts on a narrow screen', () => {
    actAs(['records.view']);
    const originalClientWidth = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'clientWidth');
    Object.defineProperty(HTMLElement.prototype, 'clientWidth', {
      configurable: true,
      get(this: HTMLElement) { return this.classList.contains('pw-patient-nav') ? 200 : 0; },
    });
    const rectSpy = vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
      if (this.classList.contains('pw-patient-nav')) return new DOMRect(100, 0, 200, 40);
      if (this.textContent?.includes('Documentos') && this.classList.contains('active')) {
        return new DOMRect(410, 0, 90, 40);
      }
      return new DOMRect(0, 0, 0, 0);
    });

    try {
      renderRail(patient(), '/documentos');
      const nav = screen.getByRole('navigation', { name: 'Secciones del expediente' });
      expect(screen.getByRole('link', { name: 'Documentos' }).classList.contains('active')).toBe(true);
      expect(nav.scrollLeft).toBeGreaterThan(0);
      expect(window.scrollY).toBe(0);
    } finally {
      rectSpy.mockRestore();
      if (originalClientWidth) Object.defineProperty(HTMLElement.prototype, 'clientWidth', originalClientWidth);
    }
  });

  it('groups patient identity and section navigation in a horizontal workspace header', () => {
    actAs(['records.manage', 'records.view']);
    renderRail(patient());

    const header = screen.getByRole('region', { name: 'Paciente y secciones del expediente' });
    expect(within(header).getByRole('heading', { level: 1, name: 'Paciente Prueba' })).toBeTruthy();
    expect(within(header).getByRole('link', { name: 'Volver a Pacientes' }).getAttribute('href')).toBe('/pacientes');
    const nav = within(header).getByRole('navigation', { name: 'Secciones del expediente' });
    const links = within(nav).getAllByRole('link');
    expect(links[0].textContent).toContain('Resumen');
    expect(links[1].textContent).toContain('Iniciar atención');
    expect(within(header).queryByRole('complementary')).toBeNull();
  });

  // Client-side, no `target`: a target forces a full page load, and the
  // atención kept mounted in the expediente would be thrown away with it.
  it('links "Iniciar atención" inside the same tab, without a page load', async () => {
    actAs(['records.manage']);
    renderRail(patient());

    const link = await screen.findByRole('link', { name: /iniciar atención/i });
    expect(link.getAttribute('href')).toBe('/pacientes/42/atencion');
    expect(link.getAttribute('target')).toBeNull();
  });

  it('shows "Atención en curso" when the patient has an open record', async () => {
    actAs(['records.manage']);
    vi.mocked(fetchPatientRecords).mockResolvedValue(page([record('in_progress')]));
    renderRail(patient());

    expect(await screen.findByRole('link', { name: /atención en curso/i })).toBeTruthy();
  });

  it('reads "Iniciar atención" when every record is already completed', async () => {
    actAs(['records.manage']);
    vi.mocked(fetchPatientRecords).mockResolvedValue(page([record('completed')]));
    renderRail(patient());

    expect(await screen.findByRole('link', { name: /iniciar atención/i })).toBeTruthy();
  });

  it('hides the entry entirely without records.manage, rather than disabling it', () => {
    actAs([]);
    renderRail(patient());

    expect(screen.queryByRole('link', { name: /atención/i })).toBeNull();
  });

  // The atención stays mounted while the doctor visits another section of the
  // same patient, so moving between sections never asks — even mid-visit.
  it('moves between sections without asking, even with an atención open', async () => {
    const user = userEvent.setup();
    actAs(['records.view']);
    useEncounterGuardStore.setState({ hasUnsavedWork: true });
    const confirmSpy = vi.spyOn(window, 'confirm');
    renderRail(patient());

    await user.click(await screen.findByRole('link', { name: 'Historial' }));

    expect(confirmSpy).not.toHaveBeenCalled();
    confirmSpy.mockRestore();
  });

});

describe('PatientRail — módulo gineco-obstétrico', () => {
  it('keeps the specialized section out of an otherwise clinical expediente', () => {
    actAs(['records.view']);
    renderRail(patient());

    expect(screen.queryByRole('link', { name: /gíneco-obstétrico/i })).toBeNull();
  });

  it('shows the section only when the server-issued capability is present', () => {
    actAs(['records.view'], ['gyneco_obstetrics']);
    renderRail(patient());

    expect(screen.getByRole('link', { name: /gíneco-obstétrico/i }).getAttribute('href')).toContain('gineco');
  });
});
