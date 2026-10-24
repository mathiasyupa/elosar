import { useMemo, useState } from 'react';
import { useHonorificGate } from '@/components/HonorificPrompt';
import { Link } from 'react-router-dom';
import { useQueries, useQuery } from '@tanstack/react-query';
import { fetchPatientRecords } from '@/api/patients';
import { fetchRecord, downloadPrescriptionPdf } from '@/api/records';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { LetterheadNotice } from '@/components/LetterheadNotice';
import { downloadBlob } from '@/lib/download';
import { formatDate, t } from '@/i18n/es';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { usePatientWorkspace } from './context';
import type { MedicalRecord } from '@/api/types';

export function PatientPrescriptionsTab() {
  const { patientId } = usePatientWorkspace();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const ensureHonorific = useHonorificGate();
  const { can } = useWorkspaceCapabilities();
  const [downloading, setDownloading] = useState<number | null>(null);

  const { data: records, isLoading } = useQuery({
    queryKey: ['patient-records', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientRecords(patientId),
  });

  const recordIds = records?.data.map((r) => r.id) ?? [];
  const details = useQueries({
    queries: recordIds.map((id) => ({ queryKey: ['record', id], queryFn: () => fetchRecord(id) })),
  });

  const withRx = useMemo<MedicalRecord[]>(
    () =>
      details
        .map((q) => q.data)
        .filter((r): r is MedicalRecord => !!r && (r.prescriptions?.length ?? 0) > 0)
        .sort((a, b) => (b.created_at ?? '').localeCompare(a.created_at ?? '')),
    [details],
  );
  const loadingDetails = details.some((q) => q.isLoading);

  const handleDownload = async (recordId: number) => {
    await ensureHonorific();
    setDownloading(recordId);
    try {
      const blob = await downloadPrescriptionPdf(recordId);
      downloadBlob(blob, `receta-${recordId}.pdf`);
      toast('Receta generada.', 'success');
    } catch {
      toast('No se pudo generar la receta.', 'error');
    } finally {
      setDownloading(null);
    }
  };

  if (isLoading || loadingDetails) return <Spinner label={t.common.loading} />;

  if (withRx.length === 0) {
    return (
      <Card>
        <EmptyState
          icon="pill"
          message="Este paciente no tiene recetas registradas."
          hint="Las recetas se emiten al registrar una consulta."
          action={
            <Link
              className="btn primary sm"
              to={`/pacientes/${patientId}/atencion?tab=rx`}
              target={`patient-workspace-${patientId}`}
            >
              <Icon name="plus" size={14} /> Nueva receta
            </Link>
          }
        />
      </Card>
    );
  }

  return (
    <div className="stack">
      {/* Above the "Receta PDF" buttons: the doctor finds out before printing,
          not after handing a blank sheet to the patient. */}
      <LetterheadNotice />

      {/* A new receta is a new atención opened straight on its Receta tab. */}
      {can('records.manage') && (
        <div className="rx-list-head">
          <Link
            className="btn primary sm"
            to={`/pacientes/${patientId}/atencion?tab=rx`}
            target={`patient-workspace-${patientId}`}
          >
            <Icon name="plus" size={14} /> Nueva receta
          </Link>
        </div>
      )}

      {withRx.map((record) => (
        <Card key={record.id} padded={false}>
          <div className="card-head">
            <div>
              <h3>{record.reason_for_consultation ?? 'Consulta'}</h3>
              <span className="sub">
                {formatDate(record.created_at)}
                {record.diagnosis ? ` · ${record.diagnosis}` : ''}
              </span>
            </div>
            <div className="row wrap">
              {/* A forgotten medication is fixed on the same visit: it reopens
                  with everything it held and the PDF reprints from it. */}
              {can('records.manage') && (
                <Link
                  className="btn sm"
                  to={`/pacientes/${patientId}/atencion?record=${record.id}&tab=rx`}
                  target={`patient-workspace-${patientId}`}
                >
                  <Icon name="edit" size={15} /> Editar receta
                </Link>
              )}
              <button className="btn primary sm" onClick={() => handleDownload(record.id)} disabled={downloading === record.id}>
                <Icon name="download" size={15} />
                {downloading === record.id ? 'Generando…' : 'Receta PDF'}
              </button>
            </div>
          </div>
          <table className="tbl">
            <thead>
              <tr>
                <th>Medicamento</th>
                <th>Cantidad</th>
                <th>Indicaciones</th>
              </tr>
            </thead>
            <tbody>
              {record.prescriptions!.map((rx) => {
                // Posology is optional since 2026-09-21. When a prescription has
                // it (written before then, or repeated from one that was), it
                // reads as a secondary line rather than three mostly-empty
                // columns.
                const posology = [
                  rx.dosage,
                  rx.frequency,
                  rx.duration_days != null ? `${rx.duration_days} días` : null,
                ]
                  .filter(Boolean)
                  .join(' · ');

                return (
                  <tr key={rx.id}>
                    <td>
                      <b>{rx.medication_name}</b>
                      {rx.concentration && <span className="muted"> · {rx.concentration}</span>}
                      {rx.is_controlled && <span className="badge red plain">Controlado</span>}
                      {posology && <div className="muted">{posology}</div>}
                    </td>
                    <td>{rx.quantity ?? '—'}</td>
                    {/* `?? '—'` alone would leave the cell blank for an empty
                        string, which is the printing-defect look this column
                        was added to remove. */}
                    <td>{rx.instructions?.trim() || '—'}</td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </Card>
      ))}
    </div>
  );
}
