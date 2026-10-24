import { useEffect, useMemo, useState } from 'react';
import { useHonorificGate } from '@/components/HonorificPrompt';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { fetchPatientRecords } from '@/api/patients';
import {
  createPatientOrder,
  deleteStudyOrder,
  downloadStudyOrderPdf,
  fetchPatientOrders,
  updateStudyOrderResult,
} from '@/api/studyOrders';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { useWorkspaceCapabilities } from '@/lib/capabilities';
import { Card } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { Icon } from '@/components/Icon';
import { LetterheadNotice } from '@/components/LetterheadNotice';
import { downloadBlob } from '@/lib/download';
import { formatDate, t } from '@/i18n/es';
import { StudyOrderBuilder } from './StudyOrderBuilder';
import { usePatientWorkspace } from './context';
import type { StudyOrderItemInput, StudyResultStatus, StudyType } from '@/api/types';

export function PatientOrdersTab() {
  const { patientId } = usePatientWorkspace();
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const ensureHonorific = useHonorificGate();
  const { can } = useWorkspaceCapabilities();
  const qc = useQueryClient();
  const canManage = can('records.manage');

  const [recordId, setRecordId] = useState<number | null>(null);
  const [type, setType] = useState<StudyType>('laboratory');
  const [items, setItems] = useState<StudyOrderItemInput[]>([]);
  const [clinicalInfo, setClinicalInfo] = useState('');
  const [priority, setPriority] = useState<'routine' | 'urgent'>('routine');
  const [toBePerformedAt, setToBePerformedAt] = useState('');
  const [notes, setNotes] = useState('');
  const [downloading, setDownloading] = useState<number | null>(null);

  const { data: records, isLoading } = useQuery({
    queryKey: ['patient-records', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientRecords(patientId),
  });

  const recordList = records?.data ?? [];
  const selectedRecord = useMemo(
    () => recordList.find((r) => r.id === recordId) ?? null,
    [recordList, recordId],
  );

  // The consultation is optional, so "none chosen yet" and "deliberately none"
  // are different states: without this flag the effect below would keep
  // re-selecting the latest visit every time the doctor cleared it.
  const [detached, setDetached] = useState(false);

  // Default to the most recent consultation — the common case is ordering
  // inside the visit you just had — but let it be dropped.
  useEffect(() => {
    if (recordId == null && !detached && recordList.length > 0) {
      setRecordId(recordList[0].id);
    }
  }, [recordList, recordId, detached]);

  useEffect(() => {
    setClinicalInfo(selectedRecord?.diagnosis ?? '');
  }, [selectedRecord]);

  const { data: orders = [], isLoading: ordersLoading } = useQuery({
    queryKey: ['patient-orders', activeWorkspaceId, patientId],
    queryFn: () => fetchPatientOrders(patientId),
  });

  // Without a consultation behind it, the clinical indication is the only thing
  // on the order that says why the study was requested. The server refuses it
  // too; this keeps the doctor from finding out only after pressing the button.
  const needsClinicalInfo = recordId == null && !clinicalInfo.trim();

  const createMutation = useMutation({
    mutationFn: () =>
      createPatientOrder(patientId, {
        medical_record_id: recordId,
        type,
        priority,
        clinical_info: clinicalInfo.trim() || null,
        notes: notes.trim() || null,
        to_be_performed_at: toBePerformedAt || null,
        items,
      }),
    onSuccess: async (order) => {
      toast('Orden de exámenes generada.', 'success');
      setItems([]);
      setNotes('');
      qc.invalidateQueries({ queryKey: ['patient-orders', activeWorkspaceId, patientId] });
      // Hand the doctor the printable order right away.
      try {
        const blob = await downloadStudyOrderPdf(order.id);
        downloadBlob(blob, `orden-${order.type === 'imaging' ? 'imagenologia' : 'laboratorio'}-${order.id}.pdf`);
      } catch {
        /* the order is saved; the PDF can be downloaded from the list */
      }
    },
    onError: () => toast('No se pudo generar la orden. Revisa los exámenes seleccionados.', 'error'),
  });

  const deleteMutation = useMutation({
    mutationFn: (orderId: number) => deleteStudyOrder(orderId),
    onSuccess: () => {
      toast('Orden eliminada.', 'success');
      qc.invalidateQueries({ queryKey: ['patient-orders', activeWorkspaceId, patientId] });
    },
    onError: () => toast('No se pudo eliminar la orden.', 'error'),
  });

  const resultMutation = useMutation({
    mutationFn: ({ orderId, status }: { orderId: number; status: StudyResultStatus }) =>
      updateStudyOrderResult(orderId, { result_status: status }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['patient-orders', activeWorkspaceId, patientId] });
    },
    onError: () => toast('No se pudo actualizar el estado del resultado.', 'error'),
  });

  const handleDownload = async (orderId: number, orderType: StudyType) => {
    await ensureHonorific();
    setDownloading(orderId);
    try {
      const blob = await downloadStudyOrderPdf(orderId);
      downloadBlob(blob, `orden-${orderType === 'imaging' ? 'imagenologia' : 'laboratorio'}-${orderId}.pdf`);
    } catch {
      toast('No se pudo descargar la orden.', 'error');
    } finally {
      setDownloading(null);
    }
  };

  if (isLoading) return <Spinner label={t.common.loading} />;

  // A patient with no consultations used to be sent away from here to register
  // one first. That was the whole problem: being asked for a repeat lab slip is
  // not a visit, and inventing one left an empty consultation in the chart.

  const switchType = (next: StudyType) => {
    if (next === type) return;
    if (items.length > 0 && !window.confirm('Cambiar de tipo vaciará los exámenes seleccionados. ¿Continuar?')) return;
    setType(next);
    setItems([]);
  };

  return (
    <div className="stack" style={{ gap: 18 }}>
      {/* Associated consultation — optional */}
      <Card>
        <div className="form-grid">
          <div>
            <label className="field-label" htmlFor="orden-consulta">
              Consulta asociada <span className="muted">· opcional</span>
            </label>
            <select
              id="orden-consulta"
              className="field"
              value={recordId ?? ''}
              onChange={(e) => {
                const next = e.target.value ? Number(e.target.value) : null;
                setRecordId(next);
                setDetached(next == null);
              }}
            >
              <option value="">Sin consulta — la pidió por teléfono o viene por adelantado</option>
              {recordList.map((r) => (
                <option key={r.id} value={r.id}>
                  {formatDate(r.created_at)} — {r.reason_for_consultation ?? 'Consulta'}
                </option>
              ))}
            </select>
            {recordId == null && (
              <p className="muted" style={{ fontSize: 12, marginTop: 6 }}>
                La orden quedará en la historia del paciente, sin colgar de ninguna consulta.
                Escribe abajo el diagnóstico o el motivo: es lo que justifica el examen.
              </p>
            )}
          </div>
        </div>
      </Card>

      {/* New order */}
      {canManage && (
        <Card>
          <div className="card-head" style={{ padding: 0, marginBottom: 14 }}>
            <h3 style={{ margin: 0 }}>Nueva solicitud de exámenes</h3>
            <div className="seg" role="tablist" style={{ display: 'inline-flex', gap: 4 }}>
              <button
                type="button"
                className={`btn sm ${type === 'laboratory' ? 'primary' : 'ghost'}`}
                onClick={() => switchType('laboratory')}
              >
                <Icon name="file" size={14} /> Laboratorio
              </button>
              <button
                type="button"
                className={`btn sm ${type === 'imaging' ? 'primary' : 'ghost'}`}
                onClick={() => switchType('imaging')}
              >
                <Icon name="grid" size={14} /> Imagenología
              </button>
            </div>
          </div>

          <div className="form-grid" style={{ marginBottom: 16 }}>
            <div className="full">
              <label className="field-label" htmlFor="orden-indicacion">
                Diagnóstico / indicación clínica{recordId == null && ' *'}
              </label>
              <textarea
                id="orden-indicacion"
                className="field"
                rows={2}
                placeholder="Motivo clínico de la solicitud (se imprime en la orden)"
                value={clinicalInfo}
                onChange={(e) => setClinicalInfo(e.target.value)}
              />
            </div>
            <div>
              <label className="field-label">Prioridad</label>
              <select className="field" value={priority} onChange={(e) => setPriority(e.target.value as 'routine' | 'urgent')}>
                <option value="routine">Rutina</option>
                <option value="urgent">Urgente</option>
              </select>
            </div>
            <div>
              <label className="field-label">Fecha a realizarse</label>
              <input
                className="field"
                type="date"
                value={toBePerformedAt}
                onChange={(e) => setToBePerformedAt(e.target.value)}
              />
            </div>
          </div>

          <StudyOrderBuilder type={type} items={items} onChange={setItems} />

          <div className="form-grid" style={{ marginTop: 16 }}>
            <div className="full">
              <label className="field-label">Otros exámenes / observaciones</label>
              <textarea
                className="field"
                rows={2}
                placeholder="Indicaciones adicionales para el laboratorio o imagenología"
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
              />
            </div>
          </div>

          {/* The PDF downloads the moment the order is generated, with no
              chance to look it over first — so the warning has to come before
              the button, not after the file lands. */}
          <LetterheadNotice className="alert-spaced" />

          <div className="row between" style={{ marginTop: 16, alignItems: 'center' }}>
            <p className="muted" style={{ fontSize: 12.5, margin: 0 }}>
              Al generar, se descarga la orden en PDF automáticamente.
            </p>
            <button
              className="btn primary"
              type="button"
              disabled={items.length === 0 || needsClinicalInfo || createMutation.isPending}
              title={needsClinicalInfo ? 'Sin consulta asociada, indica el diagnóstico o el motivo del examen.' : undefined}
              onClick={async () => { await ensureHonorific(); createMutation.mutate(); }}
            >
              <Icon name="download" size={15} />
              {createMutation.isPending ? 'Generando…' : 'Generar orden'}
            </button>
          </div>
        </Card>
      )}

      {/* Every order the patient has, not just this consultation's */}
      <Card padded={false}>
        <div className="card-head">
          <h3>Exámenes solicitados</h3>
        </div>
        {ordersLoading ? (
          <div style={{ padding: 16 }}>
            <Spinner label={t.common.loading} />
          </div>
        ) : orders.length === 0 ? (
          <EmptyState icon="file" message="Este paciente no tiene exámenes solicitados." />
        ) : (
          <table className="tbl">
            <thead>
              <tr>
                <th>Tipo</th>
                <th>Exámenes</th>
                <th>Consulta</th>
                <th>Prioridad</th>
                <th>A realizarse</th>
                <th>Resultado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {orders.map((order) => (
                <tr key={order.id}>
                  <td>
                    <b>{order.type_label}</b>
                    <span className="muted" style={{ display: 'block', fontSize: 12 }}>
                      {formatDate(order.created_at)}
                    </span>
                  </td>
                  <td>
                    {(order.items ?? []).map((it) => it.name).join(', ') || '—'}
                  </td>
                  {/* An order with no visit behind it is normal now, not a gap. */}
                  <td>
                    {order.medical_record ? (
                      <>
                        {formatDate(order.medical_record.created_at)}
                        <span className="muted" style={{ display: 'block', fontSize: 12 }}>
                          {order.medical_record.reason_for_consultation ?? 'Consulta'}
                        </span>
                      </>
                    ) : (
                      <span className="muted">Sin consulta</span>
                    )}
                  </td>
                  <td>
                    {order.priority === 'urgent' ? (
                      <span className="badge red plain">Urgente</span>
                    ) : (
                      <span className="muted">Rutina</span>
                    )}
                  </td>
                  <td>{order.to_be_performed_at ? formatDate(order.to_be_performed_at) : '—'}</td>
                  {/* Antes la orden se emitía y ahí moría: nadie sabía si el
                      examen se hizo. Esta columna es toda la diferencia entre
                      "¿ya llegó el resultado?" y llamar al laboratorio. */}
                  <td>
                    {canManage ? (
                      <select
                        className="field ord-result"
                        value={order.result_status}
                        aria-label={`Estado del resultado de la orden del ${formatDate(order.created_at)}`}
                        onChange={(e) =>
                          resultMutation.mutate({
                            orderId: order.id,
                            status: e.target.value as StudyResultStatus,
                          })
                        }
                      >
                        <option value="pending">Pendiente</option>
                        <option value="completed">Resultado recibido</option>
                        <option value="cancelled">No se realizó</option>
                      </select>
                    ) : (
                      <span className={order.result_status === 'completed' ? 'badge green' : 'muted'}>
                        {order.result_status_label}
                      </span>
                    )}
                    {order.result_received_at && (
                      <span className="muted" style={{ display: 'block', fontSize: 11.5, marginTop: 3 }}>
                        {formatDate(order.result_received_at)}
                      </span>
                    )}
                  </td>
                  <td>
                    <div className="row" style={{ gap: 6, justifyContent: 'flex-end' }}>
                      <button
                        className="btn sm"
                        onClick={() => handleDownload(order.id, order.type)}
                        disabled={downloading === order.id}
                      >
                        <Icon name="download" size={14} />
                        {downloading === order.id ? '…' : 'PDF'}
                      </button>
                      {canManage && (
                        <button
                          className="icon-btn"
                          aria-label="Eliminar orden"
                          onClick={() => {
                            if (window.confirm('¿Eliminar esta orden?')) deleteMutation.mutate(order.id);
                          }}
                        >
                          <Icon name="trash" size={16} />
                        </button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>
    </div>
  );
}
