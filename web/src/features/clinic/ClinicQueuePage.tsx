import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchClinicQueue } from '@/api/clinic';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { Kpi } from '@/components/ui/Kpi';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { t } from '@/i18n/es';
import { QueueTable } from './QueueTable';
import { QueryError } from '@/components/ui/QueryError';
import { ecuadorDateInputValue } from '@/lib/ecuadorDate';

/** Live clinic day-queue (/cola). Auto-refreshes so reception/nurse/doctor see
 *  arrivals without reloading. Date picker to review another day. */
export function ClinicQueuePage() {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const [date, setDate] = useState(ecuadorDateInputValue);

  const { data, isLoading, isError, isPaused, error, refetch, isFetching } = useQuery({
    queryKey: ['clinic-queue', activeWorkspaceId, date],
    queryFn: () => fetchClinicQueue(date),
    refetchInterval: 30_000,
  });

  return (
    <>
      <div className="page-head">
        <div>
          <h1>Sala de espera</h1>
          <p>Quién llegó, a quién le faltan los signos vitales y quién está listo para el médico</p>
        </div>
        <input
          type="date"
          className="field date-inline"
          value={date}
          onChange={(e) => setDate(e.target.value)}
          aria-label="Fecha de la cola"
        />
      </div>

      {isLoading && <Spinner label={t.common.loading} />}
      {(isError || isPaused) && (
        <QueryError error={error} area="la cola del día" onRetry={() => void refetch()} retrying={isFetching} paused={isPaused} />
      )}

      {data && (
        <div className="stack">
          <div className="grid g-4">
            <Kpi icon="users" label="En sala" value={String(data.stats.waiting)} tone="blue" />
            <Kpi icon="clock" label="Pendiente vitales" value={String(data.stats.pending_vitals)} tone="amber" />
            <Kpi icon="steth" label="Listos para médico" value={String(data.stats.ready)} tone="green" />
            <Kpi icon="check" label="Completadas" value={String(data.stats.completed)} tone="violet" />
          </div>

          <Card padded={false}>
            <div className="card-head">
              <h3>Pacientes ({data.stats.total})</h3>
              <span className="muted" style={{ fontSize: 12 }}>
                <Icon name="clock" size={13} /> Actualiza cada 30 s
              </span>
            </div>
            {data.appointments.length === 0 ? (
              <EmptyState icon="cal" message="No hay pacientes en la cola para esta fecha." />
            ) : (
              <QueueTable rows={data.appointments} />
            )}
          </Card>
        </div>
      )}
    </>
  );
}
