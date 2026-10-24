import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { fetchPatientRecords } from '@/api/patients';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { Icon } from '@/components/Icon';
import { RecordDetail } from '@/features/patients/workspace/RecordDetail';
import { formatDate } from '@/i18n/es';

interface Props {
  patientId: number;
  /** Draft being completed right now — it must not be offered as "previous". */
  excludeRecordId?: number | null;
}

/**
 * The patient's last closed consultation, collapsed, inside the encounter.
 *
 * Attending a chronic patient means asking "what did we do last time?" every
 * single visit, and until now that meant leaving the encounter for the
 * expediente and losing the half-typed consultation. Reuses RecordDetail and
 * the records endpoint — no new data, no new endpoint.
 */
export function PreviousVisit({ patientId, excludeRecordId = null }: Props) {
  const [open, setOpen] = useState(false);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);

  const { data, isLoading } = useQuery({
    queryKey: ['patient-records', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientRecords(patientId),
    enabled: Number.isFinite(patientId),
  });

  // Records come back newest first. A draft is not a previous visit, and
  // neither is the one being completed right now.
  const previous = (data?.data ?? []).find(
    (r) => r.status === 'completed' && r.id !== excludeRecordId,
  );

  if (isLoading || !previous) return null;

  return (
    <Card className="previous-visit">
      <button
        type="button"
        className="pv-toggle"
        aria-expanded={open}
        onClick={() => setOpen((v) => !v)}
      >
        <span className="pv-summary">
          <Icon name="clock" size={15} />
          <span className="pv-copy">
            <span className="pv-heading">
              <b>Consulta anterior</b>
              <span className="muted">{formatDate(previous.created_at)}</span>
            </span>
            {previous.diagnosis && (
              <span className="muted pv-dx" title={previous.diagnosis}>{previous.diagnosis}</span>
            )}
          </span>
        </span>
        <Icon name="chevron" size={15} className={open ? 'pv-chevron open' : 'pv-chevron'} />
      </button>

      {open && (
        <>
          {previous.reason_for_consultation && (
            <p className="muted pv-reason">{previous.reason_for_consultation}</p>
          )}
          <RecordDetail record={previous} />
        </>
      )}
    </Card>
  );
}
