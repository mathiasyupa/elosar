import { useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Link } from 'react-router-dom';
import {
  logEloEvent,
  suggestExams,
  suggestNote,
  suggestPrescription,
  type EloAgentKey,
  type EloContext,
  type EloExamsSuggestion,
  type EloNoteSuggestion,
  type EloRxItem,
  type EloRxSuggestion,
} from '@/api/elo';
import { emptyRow, type PrescriptionRow } from '@/features/patients/workspace/MedicationPicker';
import { Icon } from '@/components/Icon';
import { EloBot, type EloMood } from './EloBot';
import { EloExamsProposal, EloNoteProposal, EloRxProposal } from './EloProposals';

/** Elo's item as a row of the Prescripción tab. */
export function rowFromElo(item: EloRxItem): PrescriptionRow {
  return {
    ...emptyRow(),
    medication_name: item.medication_name || item.generic_name,
    generic_name: item.generic_name,
    concentration: item.concentration,
    dosage: item.dosage,
    route: item.route,
    frequency: item.frequency,
    duration_days: item.duration_days != null ? String(item.duration_days) : '',
    quantity: item.quantity != null ? String(item.quantity) : '',
    instructions: item.instructions,
    is_controlled: item.is_controlled,
  };
}

type Mode = 'replace' | 'append';

/** The agents behind this card's buttons; dictation has its own control above the fields. */
type CardAgent = Exclude<EloAgentKey, 'dictation'>;

type Proposal =
  | { agent: 'prescription'; data: EloRxSuggestion }
  | { agent: 'note'; data: EloNoteSuggestion }
  | { agent: 'exams'; data: EloExamsSuggestion };

const THINKING: Record<CardAgent, string> = {
  prescription: 'Pensando en una receta…',
  note: 'Ordenando tu nota…',
  exams: 'Buscando los exámenes…',
};

const DONE: Record<CardAgent, string> = {
  prescription: 'Listo, la receta quedó en la pestaña Receta.',
  note: 'Listo, la nota y los diagnósticos quedaron puestos.',
  exams: 'Listo, la orden quedó preparada en Órdenes. Revísala y genérala.',
};

const NAMES: Record<CardAgent, string> = { prescription: 'receta', note: 'nota y CIE-10', exams: 'exámenes' };

/** The server's own words for a 422 ("Pon un diagnóstico…"). */
function firstError(err: unknown): string | undefined {
  if (!isAxiosError(err)) return undefined;
  const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors;
  return errors ? Object.values(errors)[0]?.[0] : undefined;
}

function countOf(p: Proposal): number {
  return p.agent === 'note' ? p.data.diagnoses.length + (p.data.illness ? 1 : 0) : p.data.items.length;
}

/**
 * Elo, inside the atención (ADR-017). Three agents behind three buttons: tidy
 * the note with its CIE-10, propose a receta, propose exams. The doctor asks,
 * reads why, and accepts, edits or rejects. Nothing is written until the
 * doctor decides, and a failure is one quiet line — the atención never waits.
 */
export function EloCard({
  context,
  currentIllness,
  hasPrescription,
  onApplyRx,
  onApplyNote,
  onApplyExams,
}: {
  /** The clinical picture so far — never a name, cédula or id. */
  context: EloContext;
  currentIllness: string;
  hasPrescription: boolean;
  onApplyRx: (rows: PrescriptionRow[], mode: Mode, edit: boolean) => void;
  onApplyNote: (note: EloNoteSuggestion, mode: Mode, edit: boolean) => void;
  onApplyExams: (exams: EloExamsSuggestion) => void;
}) {
  const [proposal, setProposal] = useState<Proposal | null>(null);
  const [done, setDone] = useState<CardAgent | null>(null);

  const ask = useMutation({
    mutationFn: async (agent: CardAgent): Promise<Proposal> => {
      if (agent === 'note') return { agent, data: await suggestNote(context) };
      if (agent === 'exams') return { agent, data: await suggestExams(context) };
      return { agent, data: await suggestPrescription(context) };
    },
    onMutate: () => {
      setProposal(null);
      setDone(null);
    },
    onSuccess: (p) => {
      setProposal(p);
      logEloEvent('shown', countOf(p), p.agent);
    },
  });

  const finish = (outcome: 'accepted' | 'edited' | 'rejected') => {
    if (!proposal) return;
    logEloEvent(outcome, countOf(proposal), proposal.agent);
    setDone(outcome === 'rejected' ? null : proposal.agent);
    setProposal(null);
  };

  const decideRx = (data: EloRxSuggestion) => (edit: boolean, mode: Mode) => {
    const rows = data.items.map(rowFromElo);
    // A row without a quantity cannot be saved: send the doctor to finish it.
    onApplyRx(rows, mode, edit || rows.some((r) => !r.quantity));
    finish(edit ? 'edited' : 'accepted');
  };

  const decideNote = (data: EloNoteSuggestion) => (edit: boolean, mode: Mode) => {
    onApplyNote(data, mode, edit);
    finish(edit ? 'edited' : 'accepted');
  };

  const status = ask.isError && isAxiosError(ask.error) ? ask.error.response?.status : undefined;
  const failedAgent = ask.isError ? ask.variables : undefined;
  const error = !ask.isError
    ? null
    : status === 422
      ? firstError(ask.error) ?? 'Falta información para que Elo pueda ayudar.'
      : status === 409
        ? `Elo de ${failedAgent ? NAMES[failedAgent] : 'este agente'} está apagado.`
        : status === 429
          ? 'Elo llegó a su límite de uso por ahora. Puedes seguir sin él.'
          : 'Elo no está disponible ahora. Puedes seguir sin él.';

  const hasText = Boolean(context.reason?.trim() || context.illness?.trim() || context.exam?.trim());
  const hasDiagnosis = context.diagnoses.length > 0;
  const mood: EloMood = ask.isPending ? 'thinking' : done ? 'done' : hasText || hasDiagnosis ? 'ready' : 'idle';

  const headline = ask.isPending
    ? THINKING[ask.variables]
    : error
      ? error
      : proposal
        ? {
            prescription: proposal.agent === 'prescription' && proposal.data.items.length ? 'Te propongo esta receta:' : 'Para este diagnóstico no propongo medicación.',
            note: 'Así ordenaría tu nota:',
            exams: proposal.agent === 'exams' && proposal.data.items.length ? 'Te propongo estos exámenes:' : 'Para este caso no propongo exámenes.',
          }[proposal.agent]
        : done
          ? DONE[done]
          : hasDiagnosis
            ? 'Tengo ideas para la receta y los exámenes.'
            : hasText
              ? 'Puedo ordenar tu nota y sugerir el CIE-10.'
              : 'Escribe el motivo o pon un diagnóstico y te ayudo.';

  return (
    <section className="card elo-card" aria-label="Elo, tu asistente" aria-busy={ask.isPending}>
      <div className="elo-head">
        <EloBot mood={mood} />
        <div className="elo-head-text">
          <b>Elo</b>
          <span role="status">{headline}</span>
        </div>
      </div>

      {proposal?.agent === 'prescription' && (
        <EloRxProposal
          proposal={proposal.data}
          hasPrescription={hasPrescription}
          onDecide={decideRx(proposal.data)}
          onReject={() => finish('rejected')}
        />
      )}
      {proposal?.agent === 'note' && (
        <EloNoteProposal
          proposal={proposal.data}
          currentIllness={currentIllness}
          onDecide={decideNote(proposal.data)}
          onReject={() => finish('rejected')}
        />
      )}
      {proposal?.agent === 'exams' && (
        <EloExamsProposal
          proposal={proposal.data}
          onAccept={() => {
            onApplyExams(proposal.data);
            finish('accepted');
          }}
          onReject={() => finish('rejected')}
        />
      )}

      {status === 409 && (
        <Link className="link-btn" to="/ajustes?pane=agents">
          Encender en Ajustes
        </Link>
      )}

      {!proposal && !ask.isPending && (hasText || hasDiagnosis) && (
        <div className="elo-actions elo-cta-row">
          {hasText && (
            <button type="button" className="btn sm" onClick={() => ask.mutate('note')}>
              <Icon name="edit" size={14} /> Ordenar nota
            </button>
          )}
          {hasDiagnosis && (
            <button type="button" className="btn sm" onClick={() => ask.mutate('prescription')}>
              <Icon name="pill" size={14} /> {done === 'prescription' ? 'Proponer otra' : 'Sugerir receta'}
            </button>
          )}
          {hasDiagnosis && (
            <button type="button" className="btn sm" onClick={() => ask.mutate('exams')}>
              <Icon name="flask" size={14} /> Sugerir exámenes
            </button>
          )}
        </div>
      )}

      <p className="elo-disclaimer">Sugerencia de apoyo: el médico revisa y decide.</p>
    </section>
  );
}
