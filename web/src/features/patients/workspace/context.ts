import { createContext, useContext } from 'react';
import { useOutletContext } from 'react-router-dom';
import type { Patient } from '@/api/types';

/** Shared context exposed by PatientWorkspaceLayout to every clinical tab.
 *  Tabs read the already-loaded detailed patient instead of re-fetching or
 *  re-prompting for a patient — the whole point of the unified expediente. */
export interface PatientWorkspaceContext {
  patient: Patient;
  patientId: number;
}

/** The same context for what the layout renders outside the `Outlet` — the
 *  kept-alive atención, which must not depend on the route that is active. */
export const PatientWorkspaceReactContext = createContext<PatientWorkspaceContext | null>(null);

export function usePatientWorkspace(): PatientWorkspaceContext {
  const provided = useContext(PatientWorkspaceReactContext);
  const outlet = useOutletContext<PatientWorkspaceContext | undefined>();
  return (provided ?? outlet) as PatientWorkspaceContext;
}
