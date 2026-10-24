import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { fetchPatientRecords } from '@/api/patients';
import { voidRecord } from '@/api/records';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { useUiStore } from '@/store/ui';
import { useAuthStore } from '@/store/auth';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { formatDate, t } from '@/i18n/es';
import { CONSULTATION_TYPE_LABELS } from './StructuredExamFields';
import { RecordDetail } from './RecordDetail';
import { usePatientWorkspace } from './context';
import type { ConsultationType } from '@/api/types';

export function PatientHistoryTab() {
  const { patient, patientId } = usePatientWorkspace();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  // One consultation open at a time: the timeline is for scanning, and several
  // expanded records turn it back into a wall of text.
  const [openId, setOpenId] = useState<number | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['patient-records', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientRecords(patientId),
  });

  const records = data?.data ?? [];
  const { can } = useWorkspaceCapabilities();

  return (
    <Card>
      <div className="card-head flush">
        <div>
          <h3>Línea de tiempo clínica</h3>
          <span className="sub">{records.length} consulta(s) registrada(s)</span>
        </div>
        {/* target con nombre: reutiliza la pestaña de la atención en vez de
            duplicar la visita si el médico vuelve a pulsar. Detalle en
            PatientRail.tsx, donde vive el mismo botón. */}
        <Link
          className="btn primary sm"
          to={`/pacientes/${patientId}/atencion`}
          target={`patient-workspace-${patientId}`}
        >
          <Icon name="plus" size={14} /> Nueva consulta
        </Link>
      </div>

      {isLoading && <Spinner label={t.common.loading} />}
      {!isLoading && records.length === 0 && (
        <EmptyState
          icon="file"
          message={`${patient.name} aún no tiene consultas registradas.`}
          hint="La línea de tiempo se construye con cada consulta cerrada."
          action={
            <Link
              className="btn primary sm"
              to={`/pacientes/${patientId}/atencion`}
              target={`patient-workspace-${patientId}`}
            >
              <Icon name="plus" size={14} /> Iniciar primera consulta
            </Link>
          }
        />
      )}

      {records.length > 0 && (
        <div className="timeline">
          {records.map((record) => {
            const hasRx = (record.prescriptions?.length ?? 0) > 0;
            const kind = hasRx ? 'rx' : 'consulta';
            const isOpen = openId === record.id;
            return (
              <div key={record.id} className={`tl-item ${kind}`}>
                <div className="tl-card">
                  <div className="when">{formatDate(record.created_at)}</div>
                  <div className="what">
                    {record.reason_for_consultation ?? 'Consulta'}
                    {record.consultation_type && record.consultation_type !== 'general' && (
                      <span className="badge blue" style={{ marginLeft: 8, fontSize: 11 }}>
                        {CONSULTATION_TYPE_LABELS[record.consultation_type as ConsultationType] ?? record.consultation_type}
                      </span>
                    )}
                  </div>
                  {record.diagnosis && <p className="muted">{record.diagnosis}</p>}
                  {record.diagnoses && record.diagnoses.length > 0 && (
                    <div className="row wrap" style={{ marginTop: 8, gap: 6 }}>
                      {record.diagnoses.map((d) => (
                        <span key={d.id} className="badge blue mono" title={d.cie10_description ?? undefined}>
                          {d.cie10_code}
                        </span>
                      ))}
                    </div>
                  )}

                  {/* Until this existed, a closed consultation could not be read
                      back at all: the exam, the plan and the alarm signs were
                      written once and never shown again. */}
                  <button
                    type="button"
                    className="btn ghost sm tl-toggle"
                    aria-expanded={isOpen}
                    onClick={() => setOpenId(isOpen ? null : record.id)}
                  >
                    <Icon name="file" size={14} />
                    {isOpen ? 'Ocultar detalle' : 'Ver detalle de la consulta'}
                    <Icon
                      name="chevron"
                      size={14}
                      style={{ transform: isOpen ? 'rotate(180deg)' : undefined }}
                    />
                  </button>

                  {/* A saved visit is never frozen: it reopens with everything it
                      holds, to finish the prescription or fix a note. */}
                  {can('records.manage') && (
                    <Link
                      className="btn sm tl-toggle"
                      to={`/pacientes/${patientId}/atencion?record=${record.id}`}
                      target={`patient-workspace-${patientId}`}
                    >
                      <Icon name="edit" size={14} /> Seguir editando
                    </Link>
                  )}

                  {can('records.manage') && <VoidRecord recordId={record.id} patientId={patientId} />}

                  {isOpen && <RecordDetail record={record} />}
                </div>
              </div>
            );
          })}
        </div>
      )}
    </Card>
  );
}

/**
 * Anular an atención made by mistake. It is not erased: the server keeps it
 * with who, when and why (a clinical record cannot vanish), hides it from the
 * history and frees its appointment. Asked here, in the page — no pop-up.
 * Only its author or the clinic owner can, and never once it is invoiced.
 */
function VoidRecord({ recordId, patientId }: { recordId: number; patientId: number }) {
  const qc = useQueryClient();
  const toast = useUiStore((s) => s.toast);
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const [open, setOpen] = useState(false);
  const [reason, setReason] = useState('');

  const mutation = useMutation({
    mutationFn: () => voidRecord(recordId, reason.trim()),
    onSuccess: () => {
      toast('Atención anulada.', 'success');
      qc.invalidateQueries({ queryKey: ['patient-records', activeWorkspaceId, patientId] });
      qc.invalidateQueries({ queryKey: ['appointments'] });
    },
  });

  const error = mutation.isError
    ? (isAxiosError(mutation.error) && (mutation.error.response?.data as { message?: string } | undefined)?.message) ||
      'No se pudo anular.'
    : null;

  if (!open) {
    return (
      <button type="button" className="btn ghost sm tl-void" onClick={() => setOpen(true)}>
        <Icon name="trash" size={14} /> Anular
      </button>
    );
  }

  return (
    <form
      className="tl-void-form"
      onSubmit={(e) => {
        e.preventDefault();
        if (reason.trim()) mutation.mutate();
      }}
    >
      <label className="field-label" htmlFor={`void-${recordId}`}>¿Por qué la anulas?</label>
      <input
        id={`void-${recordId}`}
        className="field"
        placeholder="Ej.: se creó por error, era otro paciente"
        value={reason}
        maxLength={500}
        autoFocus
        onChange={(e) => setReason(e.target.value)}
      />
      <p className="muted tl-void-hint">No se borra: queda guardada con el motivo y deja de verse en el historial.</p>
      {error && <p className="field-error">{error}</p>}
      <div className="row" style={{ gap: 6 }}>
        <button type="submit" className="btn sm danger" disabled={!reason.trim() || mutation.isPending}>
          {mutation.isPending ? 'Anulando…' : 'Anular atención'}
        </button>
        <button type="button" className="btn sm ghost" onClick={() => setOpen(false)}>Cancelar</button>
      </div>
    </form>
  );
}
