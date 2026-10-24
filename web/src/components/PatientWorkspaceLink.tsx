import { useId } from 'react';
import type { AnchorHTMLAttributes, ReactNode } from 'react';

interface PatientWorkspaceLinkProps
  extends Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href' | 'target'> {
  patientId: number;
  /** The expediente section to show. The summary is the clinical entry point. */
  section?: string;
  children: ReactNode;
}

/**
 * Opens a patient's expediente in one stable, named browser tab.
 *
 * This intentionally renders a real anchor instead of using a programmatic
 * popup: browsers can focus the tab that already bears this patient's name,
 * while the screen from which the clinician came remains open.
 */
export function PatientWorkspaceLink({
  patientId,
  section = 'resumen',
  children,
  ...props
}: PatientWorkspaceLinkProps) {
  const descriptionId = useId();

  return (
    <>
      <a
        {...props}
        href={`/pacientes/${patientId}/${section}`}
        target={`patient-workspace-${patientId}`}
        aria-describedby={descriptionId}
      >
        {children}
      </a>
      <span id={descriptionId} className="sr-only">
        Se abre en una pestaña independiente del expediente.
      </span>
    </>
  );
}
