// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { Patient } from '@/api/types';
import { PatientDirectoryTable } from './PatientDirectoryTable';

const patient = {
  id: 12,
  name: 'María López',
  id_number: '1712345678',
  birth_date: '1988-03-15',
  age: 38,
  gender: 'female',
  phone: '0991234567',
  email: 'maria@example.com',
  city: 'Quito',
  address: 'Av. Amazonas N34-120',
  last_visit_at: '2026-07-28',
} satisfies Patient;

afterEach(cleanup);

describe('PatientDirectoryTable', () => {
  it('separates selection from explicit opening', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(
      <PatientDirectoryTable
        patients={[patient]}
        selectedId={null}
        onSelect={onSelect}
      />,
    );

    await user.click(screen.getByRole('button', { name: /Seleccionar a María López/i }));

    expect(onSelect).toHaveBeenCalledWith(patient);

    const openLink = screen.getByRole('link', { name: /Abrir expediente de María López/i });
    expect(openLink.getAttribute('href')).toBe('/pacientes/12/resumen');
    expect(openLink.getAttribute('target')).toBe('patient-workspace-12');
  });

  it('supports keyboard selection without double click', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(
      <PatientDirectoryTable
        patients={[patient]}
        selectedId={null}
        onSelect={onSelect}
      />,
    );

    const selectButton = screen.getByRole('button', { name: /Seleccionar a María López/i });
    selectButton.focus();
    await user.keyboard('{Enter}');

    expect(onSelect).toHaveBeenCalledWith(patient);
  });

  it('uses a named patient workspace link for the explicit expediente action', () => {
    render(
      <PatientDirectoryTable
        patients={[patient]}
        selectedId={null}
        onSelect={vi.fn()}
      />,
    );

    const openLink = screen.getByRole('link', {
      name: /Abrir expediente de María López/i,
    });
    expect(openLink.getAttribute('target')).toBe('patient-workspace-12');
  });

  it('selects the patient when clicking on the id_number (cédula) cell or row body', async () => {
    const user = userEvent.setup();
    const onSelect = vi.fn();
    render(
      <PatientDirectoryTable
        patients={[patient]}
        selectedId={null}
        onSelect={onSelect}
      />,
    );

    const cedulaCell = screen.getByText('1712345678');
    await user.click(cedulaCell);

    expect(onSelect).toHaveBeenCalledWith(patient);
  });

  it('allows selecting every visible patient from the table header', async () => {
    const user = userEvent.setup();
    const onToggleAll = vi.fn();

    render(
      <PatientDirectoryTable
        patients={[patient]}
        selectedId={null}
        onSelect={vi.fn()}
        checkedIds={new Set()}
        onToggleCheck={vi.fn()}
        onToggleAll={onToggleAll}
      />,
    );

    await user.click(screen.getByRole('checkbox', { name: 'Seleccionar todos los de esta página' }));

    expect(onToggleAll).toHaveBeenCalledTimes(1);
  });
});
