import { lazy, Suspense, useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, Outlet, useLocation, useParams, Navigate } from 'react-router-dom';
import { fetchPatient } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useEncounterGuardStore } from '@/store/encounterGuard';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';
import { PatientRail } from './PatientRail';
import { PatientWorkspaceReactContext, type PatientWorkspaceContext } from './context';
import { t } from '@/i18n/es';
import { QueryError } from '@/components/ui/QueryError';

const EncounterSection = lazy(() =>
  import('@/features/encounters/EncounterSection').then((m) => ({ default: m.EncounterSection })),
);

/** Single shell for everything clinical about one patient. The patient header
 *  (way back, identity, section tabs) stays above URL-driven content; the
 *  patient's name appears once, in that header. The detailed patient is loaded
 *  once and shared via context.
 *
 *  The atención is not an ordinary routed section: once opened it stays
 *  mounted (hidden) while the doctor visits Recetas, Historial or any other
 *  section of the same patient, the way Orpheus keeps its "Atención" tab — so
 *  stepping out to check something never costs what was typed. The `atencion`
 *  route itself renders nothing; this layout owns the screen. */
export function PatientWorkspaceLayout() {
  const { patientId: param } = useParams();
  const { pathname } = useLocation();
  const patientId = Number(param);
  const enabled = Number.isFinite(patientId);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const encounterGeneration = useEncounterGuardStore((s) => s.generation);
  // The atención is a doctor's form: without records.manage it never mounts,
  // and its URL falls back to the summary instead of an unusable form.
  const canAttend = useWorkspaceCapabilities().can('records.manage');
  const onEncounter = canAttend && pathname.endsWith('/atencion');
  const [encounterOpened, setEncounterOpened] = useState(onEncounter);
  useEffect(() => {
    if (onEncounter) setEncounterOpened(true);
  }, [onEncounter]);

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['patient', activeWorkspaceId, patientId],
    queryFn: () => fetchPatient(patientId),
    enabled,
  });

  if (isLoading) return <Spinner label={t.common.loading} />;
  if (isError || isPaused || !data) {
    return (
      <div className="stack pw-load-state">
        <Link to="/pacientes" className="btn ghost sm pw-return-link">
          <Icon name="chevron" size={16} /> {t.common.back}
        </Link>
        <QueryError
          error={error}
          area="el expediente del paciente"
          onRetry={() => void refetch()}
          retrying={isFetching} paused={isPaused}
        />
      </div>
    );
  }

  if (!canAttend && pathname.endsWith('/atencion')) return <Navigate to={`/pacientes/${patientId}/resumen`} replace />;

  const context: PatientWorkspaceContext = { patient: data, patientId };

  return (
    <PatientWorkspaceReactContext.Provider value={context}>
      <div className="patient-workspace">
        <div className={`pw-layout${onEncounter ? ' attention' : ''}`}>
          <PatientRail patient={data} />
          <div className="pw-body">
            {(encounterOpened || onEncounter) && (
              <div className="pw-encounter" hidden={!onEncounter}>
                <Suspense fallback={<Spinner label={t.common.loading} />}>
                  <EncounterSection key={encounterGeneration} />
                </Suspense>
              </div>
            )}
            <Outlet context={context} />
          </div>
        </div>
      </div>
    </PatientWorkspaceReactContext.Provider>
  );
}
