import { useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { deletePatient, previewPatientDeletion, type DeletionCandidate } from '@/api/patients';
import { Modal } from '@/components/ui/Modal';
import { Spinner } from '@/components/ui/Spinner';
import { Icon } from '@/components/Icon';

interface Props {
  ids: number[];
  onClose: () => void;
  /** Called after the operation, with what actually happened. */
  onDone: (result: { deleted: number; archived: number; failed: DeletionCandidate[] }) => void;
}

/**
 * Confirms removing patients — and, before confirming, says which of two very
 * different things is about to happen to each one.
 *
 * A patient with no trace of care is really deleted. A patient with a history is
 * archived: it leaves the list, the clinical record stays. The server decides,
 * not this dialog; what the dialog owes the doctor is knowing the answer before
 * pressing the button rather than after.
 */
export function DeletePatientsModal({ ids, onClose, onDone }: Props) {
  const [working, setWorking] = useState(false);

  const preview = useQuery({
    queryKey: ['patients', 'deletion-preview', ids],
    queryFn: () => previewPatientDeletion(ids),
  });

  const run = useMutation({
    mutationFn: async () => {
      let deleted = 0;
      let archived = 0;
      const failed: DeletionCandidate[] = [];
      const candidates = new Map<number, DeletionCandidate>(
        [...(preview.data?.deletable ?? []), ...(preview.data?.archivable ?? [])]
          .map((candidate) => [candidate.id, candidate]),
      );

      // One request per patient, on purpose: each is authorised and audited on
      // its own, and a failure halfway leaves the rest of the work valid rather
      // than rolling back a batch nobody asked to be atomic.
      for (const id of ids) {
        try {
          const { outcome } = await deletePatient(id);
          if (outcome === 'deleted') deleted += 1;
          else archived += 1;
        } catch {
          failed.push(candidates.get(id) ?? { id, name: 'Paciente' });
        }
      }

      return { deleted, archived, failed };
    },
    onSuccess: (result) => {
      onDone(result);
      onClose();
    },
  });

  const data = preview.data;
  const total = (data?.deletable.length ?? 0) + (data?.archivable.length ?? 0);

  return (
    <Modal
      title={ids.length === 1 ? 'Eliminar paciente' : `Eliminar ${ids.length} pacientes`}
      onClose={onClose}
      pending={working}
      footer={
        <>
          <button className="btn" type="button" onClick={onClose} disabled={working}>
            Cancelar
          </button>
          <button
            className="btn danger"
            type="button"
            disabled={working || preview.isLoading || total === 0}
            onClick={() => {
              setWorking(true);
              run.mutate(undefined, { onSettled: () => setWorking(false) });
            }}
          >
            {working ? 'Procesando…' : 'Confirmar'}
          </button>
        </>
      }
    >
      {preview.isLoading && <Spinner label="Revisando qué tiene cada paciente…" />}

      {preview.isError && (
        <p className="field-error">No pudimos comprobar el estado de estos pacientes. Vuelve a intentarlo.</p>
      )}

      {data && (
        <div className="stack">
          {data.deletable.length > 0 && (
            <section className="delete-group">
              <h4>
                <Icon name="trash" size={15} />
                Se eliminan ({data.deletable.length})
              </h4>
              <p className="muted">
                No tienen información clínica, atención registrada ni documentos adjuntos. Se borran de
                verdad y no se pueden recuperar.
              </p>
              <ul className="delete-names">
                {data.deletable.map((p) => (
                  <li key={p.id}>{p.name}</li>
                ))}
              </ul>
            </section>
          )}

          {data.archivable.length > 0 && (
            <section className="delete-group">
              <h4>
                <Icon name="folder" size={15} />
                Se archivan ({data.archivable.length})
              </h4>
              <p className="muted">
                Tienen información de salud o atención asociada. Salen de la lista, pero su expediente
                se conserva para proteger su historia clínica.
              </p>
              <ul className="delete-names">
                {data.archivable.map((p) => (
                  <li key={p.id}>{p.name}</li>
                ))}
              </ul>
            </section>
          )}

          {total === 0 && <p className="muted">No queda ningún paciente que quitar.</p>}
        </div>
      )}
    </Modal>
  );
}
