import { useState, type FormEvent } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import {
  addEloRules,
  deleteEloRule,
  fetchEloRules,
  teachElo,
  updateEloRule,
  type EloAgent,
  type EloChatTurn,
  type EloRule,
} from '@/api/elo';
import { EloBot } from '@/features/encounters/elo/EloBot';
import { Icon } from '@/components/Icon';
import { Spinner } from '@/components/ui/Spinner';

const EXAMPLES: Record<EloAgent['key'], string[]> = {
  prescription: ['Prefiero … a …', 'No tengo … en la farmacia', 'Para … doy … días'],
  note: ['Redacta en tercera persona', 'Para … uso el código …', 'Nota breve, máximo … frases'],
  exams: ['En … pido …', 'No pido … salvo que …', 'Mi laboratorio no hace …'],
  dictation: ['Escribe el examen por aparatos', 'Enfermedad actual en máximo … frases', 'Para … uso el código …'],
};

/** The server's own words when it has them (422 on a cédula, the 30-rule cap). */
function serverMessage(err: unknown, fallback: string): string {
  if (isAxiosError(err)) {
    const body = err.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined;
    const first = body?.errors ? Object.values(body.errors)[0]?.[0] : undefined;
    return first ?? body?.message ?? fallback;
  }
  return fallback;
}

/**
 * One agent opened: «Lo que sé de ti» (the doctor's rules, visible and
 * editable) beside the chat that teaches it. The chat only PROPOSES rules;
 * a proposal becomes a rule when the doctor presses Guardar (ADR-017).
 */
export function EloAgentDetail({ agent, onBack }: { agent: EloAgent; onBack: () => void }) {
  const qc = useQueryClient();
  const key = ['elo', 'rules', agent.key] as const;
  const rules = useQuery({ queryKey: key, queryFn: () => fetchEloRules(agent.key) });

  const refresh = (list?: EloRule[]) => {
    if (list) qc.setQueryData(key, list);
    else qc.invalidateQueries({ queryKey: key });
    qc.invalidateQueries({ queryKey: ['elo', 'agents'] });
  };

  const add = useMutation({ mutationFn: (texts: string[]) => addEloRules(agent.key, texts), onSuccess: refresh });
  const update = useMutation({
    mutationFn: ({ id, patch }: { id: number; patch: Partial<Pick<EloRule, 'text' | 'enabled'>> }) => updateEloRule(id, patch),
    onSuccess: () => refresh(),
  });
  const remove = useMutation({ mutationFn: deleteEloRule, onSuccess: () => refresh() });

  return (
    <div className="elo-detail">
      <button type="button" className="link-btn elo-back" onClick={onBack}>
        <Icon name="arrow" size={14} /> Agentes
      </button>
      <div className="elo-detail-head">
        <EloBot mood="ready" />
        <div>
          <h3>Elo · {agent.name}</h3>
          <p>{agent.description}</p>
        </div>
      </div>

      <div className="elo-detail-grid">
        <section className="card elo-rules" aria-label="Lo que sé de ti">
          <h4>Lo que sé de ti</h4>
          {rules.isLoading ? (
            <Spinner label="Cargando reglas…" />
          ) : rules.data && rules.data.length > 0 ? (
            <ul>
              {rules.data.map((rule) => (
                <RuleRow
                  key={rule.id}
                  rule={rule}
                  onSave={(text) => update.mutate({ id: rule.id, patch: { text } })}
                  onToggle={(enabled) => update.mutate({ id: rule.id, patch: { enabled } })}
                  onDelete={() => remove.mutate(rule.id)}
                />
              ))}
            </ul>
          ) : (
            <p className="elo-empty">Aún no me enseñas nada. Cuéntame cómo trabajas.</p>
          )}
          <AddRule pending={add.isPending} error={add.isError ? serverMessage(add.error, 'No se pudo guardar.') : null} onAdd={(t) => add.mutate([t])} />
          {(update.isError || remove.isError) && (
            <p className="field-error">{serverMessage(update.error ?? remove.error, 'No se pudo guardar el cambio.')}</p>
          )}
        </section>

        <TeachChat
          agent={agent}
          saving={add.isPending}
          onKeep={(text) => add.mutateAsync([text])}
        />
      </div>
    </div>
  );
}

function RuleRow({
  rule,
  onSave,
  onToggle,
  onDelete,
}: {
  rule: EloRule;
  onSave: (text: string) => void;
  onToggle: (enabled: boolean) => void;
  onDelete: () => void;
}) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(rule.text);

  const submit = (e: FormEvent) => {
    e.preventDefault();
    const text = draft.trim();
    if (text.length >= 3 && text !== rule.text) onSave(text);
    setEditing(false);
  };

  return (
    <li className={`elo-rule ${rule.enabled ? '' : 'off'}`.trim()}>
      {editing ? (
        <form className="elo-rule-edit" onSubmit={submit}>
          <input
            className="field"
            aria-label="Editar regla"
            value={draft}
            maxLength={300}
            autoFocus
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Escape') {
                setDraft(rule.text);
                setEditing(false);
              }
            }}
          />
          <button type="submit" className="btn sm primary">Guardar</button>
        </form>
      ) : (
        <span className="elo-rule-text">{rule.text}</span>
      )}
      {!editing && (
        <div className="elo-rule-actions">
          <label className="switch" title={rule.enabled ? 'Activa' : 'Pausada'}>
            <input type="checkbox" aria-label={`Regla activa: ${rule.text}`} checked={rule.enabled} onChange={(e) => onToggle(e.target.checked)} />
            <span className="track" />
          </label>
          <button type="button" className="btn sm ghost" aria-label={`Editar: ${rule.text}`} onClick={() => setEditing(true)}>
            <Icon name="edit" size={14} />
          </button>
          <button type="button" className="btn sm ghost" aria-label={`Borrar: ${rule.text}`} onClick={onDelete}>
            <Icon name="trash" size={14} />
          </button>
        </div>
      )}
    </li>
  );
}

function AddRule({ pending, error, onAdd }: { pending: boolean; error: string | null; onAdd: (text: string) => void }) {
  const [text, setText] = useState('');
  return (
    <form
      className="elo-add"
      onSubmit={(e) => {
        e.preventDefault();
        if (text.trim().length < 3) return;
        onAdd(text.trim());
        setText('');
      }}
    >
      <input
        className="field"
        aria-label="Nueva regla"
        placeholder="Escribe una regla, p. ej. «Prefiero ibuprofeno a diclofenaco»"
        value={text}
        maxLength={300}
        onChange={(e) => setText(e.target.value)}
      />
      <button type="submit" className="btn sm" disabled={pending || text.trim().length < 3}>
        <Icon name="plus" size={14} /> Agregar
      </button>
      {error && <p className="field-error">{error}</p>}
    </form>
  );
}

interface Proposal {
  text: string;
  state: 'open' | 'saved' | 'discarded';
}

interface Turn extends EloChatTurn {
  proposals?: Proposal[];
}

/** «Enséñale a Elo». The conversation lives only in this screen; it is never stored. */
function TeachChat({ agent, saving, onKeep }: { agent: EloAgent; saving: boolean; onKeep: (text: string) => Promise<unknown> }) {
  const [turns, setTurns] = useState<Turn[]>([
    { role: 'elo', text: `Hola. Cuéntame cómo trabajas y lo convierto en reglas para «${agent.name}». Tú decides cuáles guardo.` },
  ]);
  const [message, setMessage] = useState('');
  const [keepError, setKeepError] = useState<string | null>(null);

  const ask = useMutation({
    mutationFn: (text: string) => teachElo(agent.key, text, turns.map(({ role, text: t }) => ({ role, text: t }))),
    onSuccess: (answer) => {
      setTurns((prev) => [
        ...prev,
        { role: 'elo', text: answer.reply, proposals: answer.proposed_rules.map((text) => ({ text, state: 'open' as const })) },
      ]);
    },
  });

  const send = (text: string) => {
    const clean = text.trim();
    if (!clean || ask.isPending) return;
    setTurns((prev) => [...prev, { role: 'doctor', text: clean }]);
    setMessage('');
    ask.mutate(clean);
  };

  const mark = (turnIndex: number, proposalIndex: number, state: Proposal['state']) =>
    setTurns((prev) =>
      prev.map((t, i) =>
        i === turnIndex ? { ...t, proposals: t.proposals?.map((p, j) => (j === proposalIndex ? { ...p, state } : p)) } : t,
      ),
    );

  const keep = async (turnIndex: number, proposalIndex: number, text: string) => {
    setKeepError(null);
    try {
      await onKeep(text);
      mark(turnIndex, proposalIndex, 'saved');
    } catch (err) {
      setKeepError(serverMessage(err, 'No se pudo guardar la regla.'));
    }
  };

  return (
    <section className="card elo-chat" aria-label="Enséñale a Elo">
      <h4>Enséñale a Elo</h4>
      <ol className="elo-chat-log" aria-live="polite">
        {turns.map((turn, i) => (
          <li key={i} className={`elo-msg ${turn.role}`}>
            <p>{turn.text}</p>
            {turn.proposals?.map((p, j) => (
              <div key={j} className={`elo-proposal ${p.state}`}>
                <span>{p.text}</span>
                {p.state === 'open' ? (
                  <span className="elo-actions">
                    <button type="button" className="btn sm primary" disabled={saving} onClick={() => keep(i, j, p.text)}>
                      Guardar
                    </button>
                    <button type="button" className="btn sm ghost" onClick={() => mark(i, j, 'discarded')}>Descartar</button>
                  </span>
                ) : (
                  <span className="elo-proposal-state">{p.state === 'saved' ? 'Guardada' : 'Descartada'}</span>
                )}
              </div>
            ))}
          </li>
        ))}
        {ask.isPending && <li className="elo-msg elo typing">Elo está pensando…</li>}
      </ol>
      {ask.isError && <p className="field-error">{serverMessage(ask.error, 'Elo no está disponible ahora. Puedes seguir sin él.')}</p>}
      {keepError && <p className="field-error">{keepError}</p>}

      <div className="elo-chips">
        {EXAMPLES[agent.key].map((ex) => (
          <button key={ex} type="button" className="elo-chip" onClick={() => setMessage(ex.replace(/…/g, '').replace(/\s+/g, ' ').trim() + ' ')}>
            {ex}
          </button>
        ))}
      </div>
      <form
        className="elo-chat-input"
        onSubmit={(e) => {
          e.preventDefault();
          send(message);
        }}
      >
        <input
          className="field"
          aria-label="Mensaje para Elo"
          placeholder="Ej.: casi nunca uso diclofenaco, prefiero ibuprofeno"
          value={message}
          maxLength={1000}
          onChange={(e) => setMessage(e.target.value)}
        />
        <button type="submit" className="btn primary" disabled={!message.trim() || ask.isPending}>
          <Icon name="chat" size={15} /> Enviar
        </button>
      </form>
      <p className="elo-disclaimer">No escribas nombres ni cédulas de pacientes: le enseñas cómo trabajas tú.</p>
    </section>
  );
}
