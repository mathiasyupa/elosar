// @vitest-environment jsdom
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { DashboardToday } from './DashboardToday';
import type { DashboardAppointmentView } from '../dashboardView';

const appointment: DashboardAppointmentView = {
  id: 9,
  appointmentDate: '2026-07-30T10:30:00-05:00',
  status: 'scheduled',
  statusLabel: 'Agendada',
  patient: {
    id: 4,
    name: 'Ana Torres',
    idNumber: '1712345678',
    isFirstVisit: true,
  },
};

function renderDashboard({
  nextAppointment = appointment,
  appointments = [appointment],
  canConsult = false,
  canOpenPatient = false,
  onOpenAppointment = () => undefined,
}: {
  nextAppointment?: DashboardAppointmentView | null;
  appointments?: DashboardAppointmentView[];
  canConsult?: boolean;
  canOpenPatient?: boolean;
  onOpenAppointment?: (patientId: number, appointmentId: number) => void;
} = {}) {
  return render(
    <MemoryRouter future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
      <DashboardToday
        nextAppointment={nextAppointment}
        appointments={appointments}
        canConsult={canConsult}
        canOpenPatient={canOpenPatient}
        onOpenAppointment={onOpenAppointment}
      />
    </MemoryRouter>,
  );
}

describe('DashboardToday', () => {
  afterEach(cleanup);

  it('offers Atender only to clinicians and invokes the selected appointment', async () => {
    const user = userEvent.setup();
    const onOpenAppointment = vi.fn();
    renderDashboard({ canConsult: true, onOpenAppointment });

    expect(screen.getAllByText('Agendada')).toHaveLength(2);
    expect(screen.getAllByText('1ª visita')).toHaveLength(2);
    expect(screen.queryByRole('button', { name: /Abrir expediente/ })).toBeNull();

    await user.click(screen.getAllByRole('button', { name: /Atender/ })[0]!);
    expect(onOpenAppointment).toHaveBeenCalledOnce();
    expect(onOpenAppointment).toHaveBeenCalledWith(4, 9);
  });

  it('offers Abrir expediente only with patient access in the named patient workspace', () => {
    const onOpenAppointment = vi.fn();
    renderDashboard({ canOpenPatient: true, onOpenAppointment });

    expect(screen.queryByRole('button', { name: /Atender/ })).toBeNull();
    const links = screen.getAllByRole('link', { name: /Abrir expediente/ });
    expect(links).toHaveLength(2);
    links.forEach((link) => {
      expect(link.getAttribute('href')).toBe('/pacientes/4/resumen');
      expect(link.getAttribute('target')).toBe('patient-workspace-4');
    });
    expect(onOpenAppointment).not.toHaveBeenCalled();
  });

  it('does not render an appointment CTA without consultation or patient access', () => {
    renderDashboard();

    expect(screen.queryByRole('button', { name: /Atender/ })).toBeNull();
    expect(screen.queryByRole('button', { name: /Abrir expediente/ })).toBeNull();
  });

  it('sorts valid appointment dates chronologically and keeps null dates last', () => {
    const later: DashboardAppointmentView = {
      ...appointment,
      id: 12,
      appointmentDate: '2026-07-30T13:00:00-05:00',
      patient: { id: 12, name: 'Carlos Mora', idNumber: null },
    };
    const withoutTime: DashboardAppointmentView = {
      ...appointment,
      id: 14,
      appointmentDate: null,
      patient: { id: 14, name: 'Beatriz León', idNumber: null },
    };
    const earlier: DashboardAppointmentView = {
      ...appointment,
      id: 11,
      appointmentDate: '2026-07-30T08:15:00-05:00',
      patient: { id: 11, name: 'Diego Paz', idNumber: null },
    };

    renderDashboard({
      nextAppointment: null,
      appointments: [later, withoutTime, earlier],
    });

    const rows = screen.getAllByRole('listitem');
    expect(within(rows[0]!).getByText('Diego Paz')).toBeTruthy();
    expect(within(rows[1]!).getByText('Carlos Mora')).toBeTruthy();
    expect(within(rows[2]!).getByText('Beatriz León')).toBeTruthy();
  });

  it('keeps an invalid appointment date with null dates after valid dates', () => {
    const later: DashboardAppointmentView = {
      ...appointment,
      id: 22,
      appointmentDate: '2026-07-30T13:00:00-05:00',
      patient: { id: 22, name: 'Carlos Mora', idNumber: null },
    };
    const invalidDate: DashboardAppointmentView = {
      ...appointment,
      id: 23,
      appointmentDate: 'fecha-desconocida',
      patient: { id: 23, name: 'Elena Ruiz', idNumber: null },
    };
    const withoutTime: DashboardAppointmentView = {
      ...appointment,
      id: 24,
      appointmentDate: null,
      patient: { id: 24, name: 'Beatriz León', idNumber: null },
    };
    const earlier: DashboardAppointmentView = {
      ...appointment,
      id: 21,
      appointmentDate: '2026-07-30T08:15:00-05:00',
      patient: { id: 21, name: 'Diego Paz', idNumber: null },
    };

    renderDashboard({
      nextAppointment: null,
      appointments: [invalidDate, later, withoutTime, earlier],
    });

    const rows = screen.getAllByRole('listitem');
    expect(within(rows[0]!).getByText('Diego Paz')).toBeTruthy();
    expect(within(rows[1]!).getByText('Carlos Mora')).toBeTruthy();
    const trailingRows = rows.slice(2).map((row) => row.textContent ?? '');
    expect(trailingRows.some((text) => text.includes('Elena Ruiz'))).toBe(true);
    expect(trailingRows.some((text) => text.includes('Beatriz León'))).toBe(true);
  });

  it('offers a safe next step when today is empty', () => {
    renderDashboard({ nextAppointment: null, appointments: [] });

    expect(screen.getAllByText('No hay citas para hoy.')).toHaveLength(2);
    expect(screen.getAllByRole('link', { name: 'Ver agenda' })).not.toHaveLength(0);
  });
});
