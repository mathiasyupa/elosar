import { useCallback } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';

/** The browser tab a patient's expediente lives in (one per patient). */
export function patientTabName(patientId: number): string {
  return `patient-workspace-${patientId}`;
}

/**
 * Opens a patient's expediente the way the doctors asked: in its own tab, so
 * Inicio, Agenda or Pacientes stay where they were behind it. Inside that same
 * patient's expediente it simply moves there — «Iniciar atención» from the
 * expediente does not spawn yet another tab.
 *
 * Returns a function to call from a click (browsers allow the new tab only
 * from a user gesture); if the tab is blocked, it falls back to this one.
 */
export function useOpenPatient(): (patientId: number, path?: string) => void {
  const navigate = useNavigate();
  const location = useLocation();

  return useCallback(
    (patientId: number, path = 'resumen') => {
      const url = `/pacientes/${patientId}/${path}`;
      const root = `/pacientes/${patientId}`;
      if (location.pathname === root || location.pathname.startsWith(`${root}/`)) {
        navigate(url);
        return;
      }
      const tab = window.open(url, patientTabName(patientId));
      if (!tab) {
        navigate(url);
        return;
      }
      tab.focus();
    },
    [location.pathname, navigate],
  );
}
