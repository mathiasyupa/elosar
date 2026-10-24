// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { PatientWorkspaceLink } from './PatientWorkspaceLink';

afterEach(cleanup);

describe('PatientWorkspaceLink', () => {
  it('opens the summary in a tab named for its patient', () => {
    render(<PatientWorkspaceLink patientId={42}>Abrir expediente</PatientWorkspaceLink>);

    const link = screen.getByRole('link', { name: 'Abrir expediente' });
    expect(link.getAttribute('href')).toBe('/pacientes/42/resumen');
    expect(link.getAttribute('target')).toBe('patient-workspace-42');
    expect(link.getAttribute('aria-describedby')).toBeTruthy();
    expect(screen.getByText('Se abre en una pestaña independiente del expediente.').className).toContain('sr-only');
    // `noopener` makes a named target behave as `_blank` in major browsers,
    // which would create a second patient file instead of focusing this one.
    expect(link.getAttribute('rel')).toBeNull();
  });

  it('keeps the same tab name while opening another expediente section', () => {
    render(
      <PatientWorkspaceLink patientId={42} section="historial">
        Ver historial
      </PatientWorkspaceLink>,
    );

    const link = screen.getByRole('link', { name: 'Ver historial' });
    expect(link.getAttribute('href')).toBe('/pacientes/42/historial');
    expect(link.getAttribute('target')).toBe('patient-workspace-42');
  });
});
