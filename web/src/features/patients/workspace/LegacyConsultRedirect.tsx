import { useEffect } from 'react';
import { Navigate } from 'react-router-dom';
import { useUiStore } from '@/store/ui';

/** Back-compat for the old `/consultas` route (and the `?new=1` CTA). Opens the
 *  global patient picker and bounces to the patients list — the new clinical hub. */
export function LegacyConsultRedirect() {
  const openQuickSearch = useUiStore((s) => s.openQuickSearch);

  useEffect(() => {
    openQuickSearch();
  }, [openQuickSearch]);

  return <Navigate to="/pacientes" replace />;
}
