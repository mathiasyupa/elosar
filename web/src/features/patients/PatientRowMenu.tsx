import { useEffect, useRef, useState } from 'react';
import { Icon } from '@/components/Icon';

interface Props {
  patientName: string;
  onDelete: () => void;
}

/**
 * Per-row overflow menu in the patient directory.
 *
 * Exists because deleting a patient used to be reachable only *after* ticking a
 * checkbox: the toolbar with "Eliminar" appeared once you had already decided to
 * do the thing you had no way of knowing was possible. An action nobody can
 * discover is, in practice, an action that does not exist.
 *
 * Bulk selection stays — deleting several at once is still worth having. This is
 * the door for the common case of one patient.
 */
export function PatientRowMenu({ patientName, onDelete }: Props) {
  const [open, setOpen] = useState(false);
  const box = useRef<HTMLDivElement>(null);

  useEffect(() => {
    if (!open) return;

    const onPointer = (e: MouseEvent) => {
      if (!box.current?.contains(e.target as Node)) setOpen(false);
    };
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };

    document.addEventListener('mousedown', onPointer);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onPointer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open]);

  return (
    // La fila entera es clicable y abre el expediente; sin esto, usar el menú
    // abriría también el paciente por debajo.
    <div className="row-menu" ref={box} onClick={(e) => e.stopPropagation()}>
      <button
        className="btn sm ghost row-menu-trigger"
        type="button"
        aria-haspopup="menu"
        aria-expanded={open}
        aria-label={`Más acciones para ${patientName}`}
        onClick={() => setOpen((v) => !v)}
      >
        <Icon name="dots" size={16} />
      </button>

      {open && (
        <div className="row-menu-panel" role="menu">
          <button
            className="row-menu-item danger"
            type="button"
            role="menuitem"
            onClick={() => {
              setOpen(false);
              onDelete();
            }}
          >
            <Icon name="trash" size={15} />
            Eliminar paciente
          </button>
        </div>
      )}
    </div>
  );
}
