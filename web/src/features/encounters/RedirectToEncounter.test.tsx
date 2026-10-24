// @vitest-environment jsdom
import { cleanup, render, screen } from '@testing-library/react';
import { MemoryRouter, Route, Routes, useLocation, useParams } from 'react-router-dom';
import { afterEach, describe, expect, it } from 'vitest';
import { RedirectToEncounter } from './RedirectToEncounter';

/** Stands in for EncounterPage: reports where the redirect actually landed. */
function LandingProbe() {
  const { patientId } = useParams();
  const { search } = useLocation();
  return <div data-testid="landed">{`${patientId}|${search}`}</div>;
}

function renderAt(initialPath: string) {
  render(
    <MemoryRouter initialEntries={[initialPath]}>
      <Routes>
        <Route path="/pacientes/:patientId/consulta" element={<RedirectToEncounter />} />
        <Route path="/pacientes/:patientId/atencion" element={<LandingProbe />} />
      </Routes>
    </MemoryRouter>,
  );
}

afterEach(cleanup);

describe('RedirectToEncounter', () => {
  it('sends the old consultation URL to the encounter screen', () => {
    renderAt('/pacientes/42/consulta');

    expect(screen.getByTestId('landed').textContent).toBe('42|');
  });

  // The query string is what identifies WHICH visit this is. Dropping it would
  // open a blank walk-in instead of the queued patient — silently, with no error.
  it('keeps the triage draft parameter', () => {
    renderAt('/pacientes/42/consulta?draft=7');

    expect(screen.getByTestId('landed').textContent).toBe('42|?draft=7');
  });

  it('keeps the appointment parameter', () => {
    renderAt('/pacientes/42/consulta?appointment=1237');

    expect(screen.getByTestId('landed').textContent).toBe('42|?appointment=1237');
  });
});
