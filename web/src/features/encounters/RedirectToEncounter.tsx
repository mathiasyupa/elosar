import { Navigate, useLocation, useParams } from 'react-router-dom';

/** Back-compat for the encounter's two earlier addresses: it lived at
 *  `/pacientes/:patientId/consulta` before getting its own `/atencion/:id`
 *  screen, and now moved to `/pacientes/:patientId/atencion`. Keeps the query
 *  string intact: `?draft=` and `?appointment=` are what tell the encounter
 *  which visit it is, so dropping them would silently open a blank walk-in
 *  instead of the queued patient. */
export function RedirectToEncounter() {
  const { patientId } = useParams();
  const { search } = useLocation();

  return <Navigate to={`/pacientes/${patientId}/atencion${search}`} replace />;
}
