import { useEffect, useState } from 'react';
import { useHonorificGate } from '@/components/HonorificPrompt';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { createPatientOrder, downloadStudyOrderPdf } from '@/api/studyOrders';
import { StudyOrderBuilder } from '@/features/patients/workspace/StudyOrderBuilder';
import { LetterheadNotice } from '@/components/LetterheadNotice';
import { Icon } from '@/components/Icon';
import { useAuthStore } from '@/store/auth';
import { useUiStore } from '@/store/ui';
import { downloadBlob } from '@/lib/download';
import type { StudyOrderItemInput, StudyType } from '@/api/types';

interface EncounterOrdersSectionProps {
  patientId: number;
  /** El id de la consulta autoguardada, si ya existe. Por ADR-008 una orden no
   *  necesita consulta: si es null, la orden queda colgando del paciente con
   *  la indicación clínica escrita a mano como justificación. */
  recordId: number | null;
  defaultClinicalInfo: string;
  /** Elo's proposal (ADR-017). Fills the form; the order is still the doctor's to generate. */
  prefill?: OrdersPrefill | null;
}

export interface OrdersPrefill {
  /** A new key re-applies the prefill, so asking Elo again refills the form. */
  key: number;
  type: StudyType;
  clinicalInfo: string;
  items: StudyOrderItemInput[];
}

/**
 * Pedir laboratorio/imagenología sin salir de la atención. Es una versión
 * compacta de `PatientOrdersTab`: aquí la consulta asociada ya es esta misma,
 * así que no hay selector — solo el tipo, la indicación y el picker de
 * exámenes reutilizado tal cual.
 */
export function EncounterOrdersSection({ patientId, recordId, defaultClinicalInfo, prefill }: EncounterOrdersSectionProps) {
  const activeWorkspaceId = useAuthStore((s) => s.activeWorkspaceId);
  const toast = useUiStore((s) => s.toast);
  const ensureHonorific = useHonorificGate();
  const qc = useQueryClient();
  const [type, setType] = useState<StudyType>('laboratory');
  const [items, setItems] = useState<StudyOrderItemInput[]>([]);
  const [clinicalInfo, setClinicalInfo] = useState('');
  const [priority, setPriority] = useState<'routine' | 'urgent'>('routine');

  // Precarga con el diagnóstico/motivo ya escritos, pero solo mientras el
  // médico no haya tocado el campo — igual que el patrón de
  // Cie10DiagnosisPicker con la impresión clínica.
  useEffect(() => {
    setClinicalInfo((prev) => (prev ? prev : defaultClinicalInfo));
  }, [defaultClinicalInfo]);

  useEffect(() => {
    if (!prefill) return;
    setType(prefill.type);
    setItems(prefill.items);
    if (prefill.clinicalInfo) setClinicalInfo(prefill.clinicalInfo);
  }, [prefill]);

  const needsClinicalInfo = recordId == null && !clinicalInfo.trim();

  const createMutation = useMutation({
    mutationFn: () =>
      createPatientOrder(patientId, {
        medical_record_id: recordId,
        type,
        priority,
        clinical_info: clinicalInfo.trim() || null,
        items,
      }),
    onSuccess: async (order) => {
      toast('Orden de exámenes generada.', 'success');
      setItems([]);
      qc.invalidateQueries({ queryKey: ['patient-orders', activeWorkspaceId, patientId] });
      qc.invalidateQueries({ queryKey: ['patient-records', activeWorkspaceId, patientId] });
      try {
        const blob = await downloadStudyOrderPdf(order.id);
        downloadBlob(blob, `orden-${order.type === 'imaging' ? 'imagenologia' : 'laboratorio'}-${order.id}.pdf`);
      } catch {
        /* la orden ya quedó guardada; el PDF se puede bajar luego desde Exámenes */
      }
    },
    onError: () => toast('No se pudo generar la orden. Revisa los exámenes seleccionados.', 'error'),
  });

  const switchType = (next: StudyType) => {
    if (next === type) return;
    if (items.length > 0 && !window.confirm('Cambiar de tipo vaciará los exámenes seleccionados. ¿Continuar?')) return;
    setType(next);
    setItems([]);
  };

  return (
    <div className="stack" style={{ gap: 16 }}>
      <p className="muted" style={{ fontSize: 12.5, margin: 0 }}>
        {recordId
          ? 'La orden quedará asociada a esta consulta.'
          : 'Esta consulta todavía no tiene id propio — la orden se guardará en la historia del paciente con la indicación clínica de abajo como justificación.'}
      </p>

      <div className="card-head" style={{ padding: 0 }}>
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

      <div className="form-grid">
        <div className="full">
          <label className="field-label" htmlFor="enc-orden-indicacion">
            Diagnóstico / indicación clínica{recordId == null && ' *'}
          </label>
          <textarea
            id="enc-orden-indicacion"
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
      </div>

      <StudyOrderBuilder type={type} items={items} onChange={setItems} />

      <LetterheadNotice className="alert-spaced" />

      <div className="row between" style={{ alignItems: 'center' }}>
        <p className="muted" style={{ fontSize: 12.5, margin: 0 }}>
          Al generar, se descarga la orden en PDF automáticamente.
        </p>
        <button
          className="btn primary"
          type="button"
          disabled={items.length === 0 || needsClinicalInfo || createMutation.isPending}
          title={needsClinicalInfo ? 'Indica el diagnóstico o el motivo del examen.' : undefined}
          onClick={async () => { await ensureHonorific(); createMutation.mutate(); }}
        >
          <Icon name="download" size={15} />
          {createMutation.isPending ? 'Generando…' : 'Generar orden'}
        </button>
      </div>
    </div>
  );
}
