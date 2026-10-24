import { useRef, useState } from 'react';
import { isAxiosError } from 'axios';
import { voidRecord } from '@/api/records';
import { Modal } from '@/components/ui/Modal';

interface VoidEncounterDialogProps {
  recordId: number;
  onClose: () => void;
  onVoided: () => void;
}

/** Confirms voiding an atención saved by mistake and asks why: the reason
 *  stays on file next to the voided record. */
export function VoidEncounterDialog({ recordId, onClose, onVoided }: VoidEncounterDialogProps) {
  const [reason, setReason] = useState('');
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const reasonRef = useRef<HTMLTextAreaElement>(null);

  const submit = async () => {
    if (!reason.trim()) {
      setError('Escribe por qué se anula.');
      reasonRef.current?.focus();
      return;
    }
    setPending(true);
    setError(null);
    try {
      await voidRecord(recordId, reason.trim());
      onVoided();
    } catch (e) {
      setError(
        (isAxiosError(e) && (e.response?.data as { message?: string })?.message) ||
          'No se pudo anular la atención.',
      );
      setPending(false);
    }
  };

  return (
    <Modal
      title="Anular atención"
      description="La atención sale del historial y su cita vuelve a quedar pendiente (o se cancela si la creó esta atención). Queda archivada con el motivo; no se borra."
      onClose={onClose}
      pending={pending}
      initialFocusRef={reasonRef}
      footer={
        <>
          <button className="btn ghost" type="button" onClick={onClose} disabled={pending}>
            Volver
          </button>
          <button className="btn danger" type="button" onClick={() => void submit()} disabled={pending}>
            {pending ? 'Anulando…' : 'Anular atención'}
          </button>
        </>
      }
    >
      <label className="field-label" htmlFor="void-reason">Motivo de la anulación *</label>
      <textarea
        id="void-reason"
        ref={reasonRef}
        className="field"
        rows={3}
        placeholder="Ej.: se abrió en el paciente equivocado"
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? 'void-reason-error' : undefined}
        value={reason}
        onChange={(e) => setReason(e.target.value)}
      />
      {error && <p id="void-reason-error" className="field-error">{error}</p>}
    </Modal>
  );
}
