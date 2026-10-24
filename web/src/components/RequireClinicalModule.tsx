import type { ReactNode } from 'react';
import { Navigate } from 'react-router-dom';
import type { ClinicalModule } from '@/api/types';
import { useClinicalModules } from '@/lib/capabilities';

/**
 * Presentation guard for a server-authorized clinical module. It keeps a deep
 * link from rendering a sensitive screen while the API remains the authority
 * that rejects direct requests from an ineligible professional.
 */
export function RequireClinicalModule({
  module,
  children,
}: {
  module: ClinicalModule;
  children: ReactNode;
}) {
  const clinicalModules = useClinicalModules();

  if (!clinicalModules.has(module)) {
    return <Navigate to="../resumen" replace />;
  }

  return <>{children}</>;
}
