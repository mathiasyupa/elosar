import { useState, type ReactNode } from 'react';
import type { EloExamsSuggestion, EloNoteSuggestion, EloRxSuggestion } from '@/api/elo';
import { Icon } from '@/components/Icon';

export type Mode = 'replace' | 'append';

/** The «¿Por qué?» and «Usé tus reglas» lines every proposal shares. */
export function Why({ reason, rules }: { reason: string; rules?: string[] }) {
  const [open, setOpen] = useState(false);
  return (
    <>
      {rules && rules.length > 0 && <p className="elo-rules-used">Usé tus reglas: {rules.join(' · ')}</p>}
      {reason && (
        <div className="elo-why">
          <button type="button" className="link-btn" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
            ¿Por qué?
          </button>
          {open && <p>{reason}</p>}
        </div>
      )}
    </>
  );
}

/**
 * Aceptar / Editar / Rechazar. When something is already there, Aceptar and
 * Editar first ask whether to replace it or add to it.
 */
export function Decide({
  hasExisting,
  existingLabel,
  acceptLabel = 'Aceptar',
  onDecide,
  onReject,
  children,
}: {
  hasExisting: boolean;
  existingLabel: string;
  acceptLabel?: string;
  onDecide: (edit: boolean, mode: Mode) => void;
  onReject: () => void;
  children?: ReactNode;
}) {
  const [ask, setAsk] = useState<null | boolean>(null);

  const decide = (edit: boolean) => (hasExisting ? setAsk(edit) : onDecide(edit, 'replace'));

  if (ask !== null) {
    return (
      <div className="elo-ask" role="group" aria-label={existingLabel}>
        <span>{existingLabel}</span>
        <div className="elo-actions">
          <button type="button" className="btn sm primary" onClick={() => onDecide(ask, 'replace')}>Reemplazar</button>
          <button type="button" className="btn sm" onClick={() => onDecide(ask, 'append')}>Agregar</button>
        </div>
      </div>
    );
  }

  return (
    <div className="elo-actions">
      <button type="button" className="btn sm primary" onClick={() => decide(false)}>
        <Icon name="check" size={14} /> {acceptLabel}
      </button>
      <button type="button" className="btn sm" onClick={() => decide(true)}>Editar</button>
      <button type="button" className="btn sm ghost" onClick={onReject}>Rechazar</button>
      {children}
    </div>
  );
}

export function EloRxProposal({
  proposal,
  hasPrescription,
  onDecide,
  onReject,
}: {
  proposal: EloRxSuggestion;
  hasPrescription: boolean;
  onDecide: (edit: boolean, mode: Mode) => void;
  onReject: () => void;
}) {
  if (proposal.items.length === 0) {
    return (
      <>
        <Why reason={proposal.reason} rules={proposal.rules_applied} />
        <div className="elo-actions">
          <button type="button" className="btn sm" onClick={onReject}>Entendido</button>
        </div>
      </>
    );
  }

  return (
    <>
      <ol className="elo-items" aria-label="Receta propuesta">
        {proposal.items.map((item, i) => (
          <li key={`${item.generic_name}-${i}`}>
            <b>
              {item.medication_name && item.medication_name !== item.generic_name
                ? `${item.medication_name} (${item.generic_name})`
                : item.generic_name}
              {item.concentration ? ` ${item.concentration}` : ''}
            </b>
            <span>
              {[item.dosage, item.frequency, item.duration_days ? `${item.duration_days} días` : null].filter(Boolean).join(' · ')}
            </span>
            {item.is_controlled && <span className="badge amber plain">Controlado</span>}
          </li>
        ))}
      </ol>
      {proposal.removed_for_allergy.length > 0 && (
        <p className="elo-note warn">Quité {proposal.removed_for_allergy.join(', ')} por la alergia registrada.</p>
      )}
      <Why reason={proposal.reason} rules={proposal.rules_applied} />
      <Decide
        hasExisting={hasPrescription}
        existingLabel="Ya hay medicamentos en la receta."
        onDecide={onDecide}
        onReject={onReject}
      />
    </>
  );
}

export function EloNoteProposal({
  proposal,
  currentIllness,
  onDecide,
  onReject,
}: {
  proposal: EloNoteSuggestion;
  currentIllness: string;
  onDecide: (edit: boolean, mode: Mode) => void;
  onReject: () => void;
}) {
  const before = currentIllness.trim();
  return (
    <>
      {proposal.illness && (
        <div className="elo-diff">
          {before && (
            <div className="elo-diff-before">
              <span>Lo que escribiste</span>
              <p>{before}</p>
            </div>
          )}
          <div className="elo-diff-after">
            <span>Enfermedad actual</span>
            <p>{proposal.illness}</p>
          </div>
        </div>
      )}
      {proposal.diagnoses.length > 0 && (
        <ol className="elo-items" aria-label="Diagnósticos propuestos">
          {proposal.diagnoses.map((d) => (
            <li key={d.code}>
              <b>{d.code}</b>
              <span>{d.description}</span>
              {d.type === 'presumptive' && <span className="badge plain">Presuntivo</span>}
            </li>
          ))}
        </ol>
      )}
      {proposal.dropped_codes > 0 && (
        <p className="elo-note">Descarté {proposal.dropped_codes === 1 ? 'un código' : `${proposal.dropped_codes} códigos`} que no está en el CIE-10.</p>
      )}
      <Why reason={proposal.reason} rules={proposal.rules_applied} />
      <Decide
        hasExisting={before !== '' && proposal.illness !== ''}
        existingLabel="Ya escribiste la enfermedad actual."
        onDecide={onDecide}
        onReject={onReject}
      />
    </>
  );
}

export function EloExamsProposal({
  proposal,
  onAccept,
  onReject,
}: {
  proposal: EloExamsSuggestion;
  onAccept: () => void;
  onReject: () => void;
}) {
  if (proposal.items.length === 0) {
    return (
      <>
        <Why reason={proposal.reason} rules={proposal.rules_applied} />
        <div className="elo-actions">
          <button type="button" className="btn sm" onClick={onReject}>Entendido</button>
        </div>
      </>
    );
  }

  return (
    <>
      <p className="elo-note">{proposal.type === 'imaging' ? 'Imagenología' : 'Laboratorio'}</p>
      <ol className="elo-items" aria-label="Exámenes propuestos">
        {proposal.items.map((item) => (
          <li key={item.name}>
            <b>{item.name}</b>
            {!item.in_catalog && <span className="badge amber plain">Fuera del catálogo</span>}
          </li>
        ))}
      </ol>
      <Why reason={proposal.reason} rules={proposal.rules_applied} />
      <div className="elo-actions">
        <button type="button" className="btn sm primary" onClick={onAccept}>
          <Icon name="check" size={14} /> Llevar a la orden
        </button>
        <button type="button" className="btn sm ghost" onClick={onReject}>Rechazar</button>
      </div>
    </>
  );
}
