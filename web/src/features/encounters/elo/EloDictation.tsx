import { useEffect, useRef, useState } from 'react';
import { useMutation } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Link } from 'react-router-dom';
import { logEloEvent, suggestFromDictation, type EloContext, type EloDictationSuggestion } from '@/api/elo';
import { Icon } from '@/components/Icon';
import { Spinner } from '@/components/ui/Spinner';
import { isSpeechSupported, scrubIdentifiers, startDictation, type DictationError } from '@/lib/speech';
import { Decide, Why, type Mode } from './EloProposals';

/** The atención's text fields Elo can fill from what it heard. */
export type DictationFields = Pick<EloDictationSuggestion, 'reason' | 'illness' | 'evolution' | 'exam'>;

const FIELDS: [keyof DictationFields, string][] = [
  ['reason', 'Motivo de atención'],
  ['illness', 'Enfermedad actual'],
  ['evolution', 'Evolución'],
  ['exam', 'Examen físico'],
];

const MIC_ERRORS: Record<DictationError, string> = {
  denied: 'Permite el micrófono en el navegador para que Elo escuche.',
  'no-mic': 'No encuentro un micrófono conectado.',
  network: 'Se cortó la conexión mientras Elo escuchaba. Intenta de nuevo.',
  other: 'Elo no pudo escuchar. Intenta de nuevo.',
};

function countOf(s: EloDictationSuggestion): number {
  return FIELDS.filter(([key]) => s[key].trim()).length + s.diagnoses.length;
}

function clock(seconds: number): string {
  return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

type Phase = 'idle' | 'listening' | 'proposal';

/**
 * "Escuchar con Elo": the doctor talks with the patient, the words appear as
 * they are said, and Elo sorts them into motivo, enfermedad actual, evolución,
 * examen físico and CIE-10. Only text leaves the browser's speech service,
 * with cédula-like numbers scrubbed first; nothing is written until the
 * doctor accepts (ADR-017).
 */
export function EloDictation({
  context,
  current,
  onApply,
}: {
  /** The clinical picture so far — never a name, cédula or id. */
  context: EloContext;
  /** What the doctor already wrote in each field. */
  current: DictationFields;
  onApply: (suggestion: EloDictationSuggestion, mode: Mode, edit: boolean) => void;
}) {
  const [supported] = useState(isSpeechSupported);
  const [phase, setPhase] = useState<Phase>('idle');
  const [heard, setHeard] = useState('');
  const [interim, setInterim] = useState('');
  const [seconds, setSeconds] = useState(0);
  const [micError, setMicError] = useState<string | null>(null);
  const [proposal, setProposal] = useState<EloDictationSuggestion | null>(null);
  const [done, setDone] = useState(false);
  const [stopping, setStopping] = useState(false);
  const session = useRef<ReturnType<typeof startDictation> | null>(null);

  const sort = useMutation({
    mutationFn: (transcript: string) => suggestFromDictation(context, transcript),
    onSuccess: (s) => {
      setProposal(s);
      setPhase('proposal');
      logEloEvent('shown', countOf(s), 'dictation');
    },
    onError: () => setPhase('idle'),
  });

  useEffect(() => {
    if (phase !== 'listening') return;
    const timer = window.setInterval(() => setSeconds((s) => s + 1), 1000);
    return () => window.clearInterval(timer);
  }, [phase]);

  // Leaving the atención mid-sentence turns the microphone off.
  useEffect(() => () => session.current?.cancel(), []);

  if (!supported) return null;

  const listen = () => {
    setHeard('');
    setInterim('');
    setSeconds(0);
    setMicError(null);
    setProposal(null);
    setDone(false);
    sort.reset();
    try {
      session.current = startDictation({
        onText: (finalText, words) => {
          setHeard(finalText);
          setInterim(words);
        },
        onError: (error) => {
          session.current = null;
          setMicError(MIC_ERRORS[error]);
          setPhase('idle');
        },
      });
      setPhase('listening');
    } catch {
      setMicError(MIC_ERRORS.other);
    }
  };

  const stopAndSort = async () => {
    const active = session.current;
    session.current = null;
    if (!active) return;
    setStopping(true);
    const transcript = scrubIdentifiers(await active.stop()).trim();
    setStopping(false);
    if (transcript.length < 10) {
      setMicError('No alcancé a escuchar nada. Intenta de nuevo, un poco más cerca del micrófono.');
      setPhase('idle');
      return;
    }
    setHeard(transcript);
    setInterim('');
    sort.mutate(transcript);
  };

  const cancel = () => {
    session.current?.cancel();
    session.current = null;
    setPhase('idle');
  };

  const finish = (outcome: 'accepted' | 'edited' | 'rejected') => {
    if (proposal) logEloEvent(outcome, countOf(proposal), 'dictation');
    setDone(outcome !== 'rejected');
    setProposal(null);
    setPhase('idle');
  };

  const status = sort.isError && isAxiosError(sort.error) ? sort.error.response?.status : undefined;
  const sortError = !sort.isError
    ? null
    : status === 409
      ? 'Escuchar la consulta está apagado en Ajustes → Agentes.'
      : status === 429
        ? 'Elo llegó a su límite de uso por ahora. Puedes seguir sin él.'
        : status === 422
          ? 'Elo escuchó muy poco. Habla un poco más y vuelve a intentar.'
          : 'Elo no está disponible ahora. Puedes seguir sin él.';
  const error = micError ?? sortError;
  const busy = stopping || sort.isPending;

  const filled = proposal ? FIELDS.filter(([key]) => proposal[key].trim()) : [];
  const overlaps = filled.some(([key]) => current[key].trim());

  return (
    <div className="elo-listen">
      <div className="elo-listen-bar">
        {phase === 'idle' && !busy && (
          <>
            <span className="elo-listen-hint" role="status">
              {error ?? (done ? 'Listo, Elo llenó la atención. Revísala antes de guardar.' : 'Habla con tu paciente y Elo llena la atención.')}
            </span>
            {status === 409 && (
              <Link className="link-btn" to="/ajustes?pane=agents">Encender en Ajustes</Link>
            )}
            <button type="button" className="btn sm elo-listen-btn" onClick={listen}>
              <Icon name="mic" size={15} /> {done ? 'Escuchar de nuevo' : 'Escuchar con Elo'}
            </button>
          </>
        )}
      </div>

      {phase === 'listening' && !busy && (
        <section className="card elo-listen-panel" aria-label="Elo está escuchando">
          <div className="elo-listen-head">
            <span className="elo-listen-dot" aria-hidden="true" />
            <b>Elo está escuchando</b>
            <span className="elo-listen-clock" aria-label={`${seconds} segundos`}>{clock(seconds)}</span>
          </div>
          <p className="elo-listen-live" aria-live="polite">
            {heard || interim ? (
              <>
                {heard}
                {interim && <span className="elo-listen-interim"> {interim}</span>}
              </>
            ) : (
              <span className="elo-listen-interim">Empieza a hablar…</span>
            )}
          </p>
          <div className="elo-actions">
            <button type="button" className="btn sm primary" onClick={() => void stopAndSort()}>
              <Icon name="check" size={14} /> Detener y ordenar
            </button>
            <button type="button" className="btn sm ghost" onClick={cancel}>Cancelar</button>
          </div>
        </section>
      )}

      {busy && (
        <section className="card elo-listen-panel" aria-busy="true">
          <Spinner label="Elo está ordenando la consulta…" />
          <p className="elo-listen-live">{heard}</p>
        </section>
      )}

      {phase === 'proposal' && proposal && (
        <section className="card elo-listen-panel" aria-label="Lo que Elo escuchó">
          <div className="elo-listen-head">
            <Icon name="mic" size={15} />
            <b>Así llenaría la atención:</b>
          </div>
          {filled.length === 0 && proposal.diagnoses.length === 0 ? (
            <p className="elo-note">No escuché datos clínicos para llenar. Intenta de nuevo.</p>
          ) : (
            <dl className="elo-dictation-fields">
              {filled.map(([key, label]) => (
                <div key={key}>
                  <dt>{label}</dt>
                  <dd>{proposal[key]}</dd>
                </div>
              ))}
            </dl>
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
          <Why reason={proposal.why} rules={proposal.rules_applied} />
          {filled.length === 0 && proposal.diagnoses.length === 0 ? (
            <div className="elo-actions">
              <button type="button" className="btn sm" onClick={() => finish('rejected')}>Entendido</button>
            </div>
          ) : (
            <Decide
              hasExisting={overlaps}
              existingLabel="Ya escribiste en algunos de estos campos."
              onDecide={(edit, mode) => {
                onApply(proposal, mode, edit);
                finish(edit ? 'edited' : 'accepted');
              }}
              onReject={() => finish('rejected')}
            />
          )}
          <p className="elo-disclaimer">Sugerencia de apoyo: el médico revisa y decide.</p>
        </section>
      )}
    </div>
  );
}
